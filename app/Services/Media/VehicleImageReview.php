<?php

namespace App\Services\Media;

use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Import\CatalogImportIdentity;
use App\Services\Import\ImportImageDownloader;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** An image-only, reviewable scope. Never creates vehicles, imports or catalogue jobs. */
class VehicleImageReview
{
    public function __construct(private readonly VehicleImageQuality $quality, private readonly ImportImageDownloader $downloader) {}

    public function plan(bool $verify = false, ?string $xlsx = null, int $after = 0, int $limit = 0, bool $redownloadAll = false): array
    {
        if ($redownloadAll && ($xlsx === null || ! is_file($xlsx))) {
            throw new RuntimeException('Для полного повторного скачивания нужен актуальный --xlsx.');
        }
        if ($verify && ! config('vehicle_images.verifier_url')) {
            throw new RuntimeException('Перед --verify настройте визуальную проверку. Без неё результат неизвестен.');
        }
        $urls = [];
        $source = null;
        $unresolved = 0;
        if ($xlsx !== null) {
            $source = ['path' => realpath($xlsx), 'sha256' => hash_file('sha256', $xlsx)];
            $identity = app(CatalogImportIdentity::class);
            foreach (app(SpreadsheetReader::class)->catalogImageRows($xlsx) as $entry) {
                if (! $entry['row']) {
                    $unresolved++;

                    continue;
                }
                $v = $identity->vehicle($entry['row']);
                if ($v['error'] !== null || $v['generation_id'] === null) {
                    $unresolved++;

                    continue;
                }
                $id = $v['generation_id'];
                $urls[$id] = array_slice(array_values(array_unique([...($urls[$id] ?? []), ...$entry['row']['_photo_urls']])), 0, 3);
            }
            if (! hash_equals($source['sha256'], hash_file('sha256', $xlsx))) {
                throw new RuntimeException('Excel изменился во время чтения фотографий.');
            }
        }
        $entries = [];
        $query = VehicleGeneration::with('model.make')->where('id', '>', $after)->orderBy('id');
        foreach ($query->lazyById(50) as $g) {
            if ($limit > 0 && count($entries) >= $limit) {
                break;
            }
            $protected = app(CatalogImportProtections::class)->protectsVehicle('generation', $g->id);
            $manual = $g->image && trim((string) $g->image_source_url) === '';
            $unknownOrigin = $redownloadAll && ! $this->downloader->hasAutomaticVehicleImageProvenance($g);
            $outsideSource = $redownloadAll && ! array_key_exists($g->id, $urls);
            $review = $redownloadAll ? ['state' => 'not_reviewed', 'reason' => 'fresh_download_requested'] : $this->quality->stored($g, $verify);
            // Full redownload is exclusively based on this workbook, never a stale saved URL.
            $candidates = array_slice(array_values(array_unique(array_filter([...($urls[$g->id] ?? []), ...($redownloadAll ? [] : [$g->image_source_url])]))), 0, 3);
            $action = ($protected || $manual || $unknownOrigin || $outsideSource) ? 'keep' : ($redownloadAll ? 'redownload' : match ($review['state']) {
                'rejected' => 'repair', 'missing' => $candidates ? 'fetch' : 'empty',
                'unverified' => 'review', default => 'keep',
            });
            $entries[$g->id] = ['id' => $g->id, 'vehicle' => $this->quality->vehicle($g), 'fingerprint' => $this->quality->fingerprint($g),
                'assessment' => $review, 'action' => $action, 'urls' => $candidates,
                'preservation' => $protected ? 'permanent_protection' : ($manual ? 'manual_image' : ($unknownOrigin ? 'unknown_origin' : ($outsideSource ? 'outside_resolved_excel_scope' : null))),
                'previous' => $g->only(['image', 'image_source_url', 'image_checksum', 'image_conversions'])];
        }
        $backup = $redownloadAll ? $this->backup($entries) : null;
        $plan = ['version' => $redownloadAll ? 2 : 1, 'mode' => $redownloadAll ? 'redownload_all' : 'repair',
            'backup' => $backup, 'database' => $this->database(), 'policy' => $this->quality->policy(),
            'expires_at' => now()->addDay()->timestamp, 'source' => $source, 'unresolved_source_rows' => $unresolved,
            'counts' => array_count_values(array_column($entries, 'action')), 'entries' => $entries];

        return $plan + ['seal' => $this->seal($plan)];
    }

    /** Bounded and resumable. Only a separately approved signed image scope can write. */
    public function apply(array $plan, string $confirmation, int $limit = 50, bool $allBatches = false, ?callable $onBatch = null): array
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Повторная обработка разрешена только в local/testing.');
        }
        $body = $plan;
        unset($body['seal']);
        if (! hash_equals($this->seal($body), $plan['seal'] ?? '') || ! hash_equals($plan['seal'], $confirmation)
            || $plan['database'] !== $this->database() || $plan['policy'] !== $this->quality->policy() || $plan['expires_at'] < now()->timestamp) {
            throw new RuntimeException('Подтверждение отсутствует или план изображений устарел.');
        }
        if ($plan['source'] && (! is_file($plan['source']['path']) || ! hash_equals($plan['source']['sha256'], hash_file('sha256', $plan['source']['path'])))) {
            throw new RuntimeException('Источник фотографий изменился.');
        }

        Storage::disk('local')->makeDirectory('vehicle-image-reviews');
        $lock = fopen(Storage::disk('local')->path('vehicle-image-reviews/apply.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Повторная обработка изображений уже выполняется.');
        }
        try {
            $redownload = ($plan['mode'] ?? 'repair') === 'redownload_all';
            if ($redownload) {
                $this->assertBackup($plan);
            }
            $pending = array_values(array_filter($plan['entries'], fn ($entry) => in_array($entry['action'], ['repair', 'fetch', 'redownload'], true)));
            $out = [];
            foreach (array_chunk($pending, max(1, min(50, $limit))) as $batch) {
                if ($plan['expires_at'] < now()->timestamp || $plan['policy'] !== $this->quality->policy()
                    || ($plan['source'] && (! is_file($plan['source']['path']) || ! hash_equals($plan['source']['sha256'], hash_file('sha256', $plan['source']['path']))))) {
                    throw new RuntimeException('План или источник изменились между пакетами; обработка остановлена.');
                }
                $selected = [];
                foreach ($batch as $entry) {
                    if ($this->completed($plan, $entry)) {
                        continue;
                    }
                    $this->assertCurrent($entry);
                    $selected[] = $entry;
                }
                $batchOut = [];
                foreach ($selected as $entry) {
                    // A crash after the SQL commit but before the journal write is detected here on resume.
                    $g = $this->assertCurrent($entry);
                    $result = 'empty';
                    $outcome = 'absent';
                    foreach ($entry['urls'] as $url) {
                        try {
                            $g = $this->downloader->downloadVehicleGenerationImage($g, $url, $entry['fingerprint'], true, $redownload);
                            $result = $redownload ? 'downloaded' : 'saved';
                            break;
                        } catch (VehicleImageNotFound $error) {
                            if ($error->outcome === 'rejected') {
                                $outcome = 'rejected';
                            }
                            // No verified candidate: a proven bad old photo may be cleared; unknown photos never are.
                        }
                    }
                    if (! in_array($result, ['saved', 'downloaded'], true) && ($entry['action'] === 'repair' || $redownload)) {
                        $g = $this->downloader->clearRejectedVehicleImage($g, $entry['fingerprint'], $redownload);
                        $result = $redownload ? $outcome : 'cleared';
                    }
                    $receipt = ['id' => $g->id, 'plan_seal' => $plan['seal'], 'before' => $entry['fingerprint'],
                        'result' => $result, 'fingerprint' => $this->quality->fingerprint($g)];
                    $this->writeCompletion($plan, $entry, $receipt);
                    $out[$g->id] = $result;
                    $batchOut[$g->id] = $result;
                }
                if ($batchOut && $onBatch) {
                    $onBatch($batchOut);
                }
                if ($selected && ! $allBatches) {
                    break;
                }
            }

            return $out;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function summary(array $plan): array
    {
        $counts = ['downloaded' => 0, 'rejected' => 0, 'absent' => 0, 'unchanged' => 0, 'pending' => 0];
        foreach ($plan['entries'] as $entry) {
            if (! in_array($entry['action'], ['repair', 'fetch', 'redownload'], true)) {
                $counts['unchanged']++;
            } elseif ($this->completed($plan, $entry)) {
                $receipt = json_decode(Storage::disk('local')->get($this->completionPath($plan, $entry)), true)['receipt'];
                $key = match ($receipt['result']) {
                    'saved', 'downloaded' => 'downloaded', 'cleared', 'rejected' => 'rejected', default => 'absent',
                };
                $counts[$key]++;
            } else {
                $counts['pending']++;
            }
        }

        return $counts;
    }

    /** Private physical copies, including thumbnails; bindings are signed in plan.entries.previous. */
    private function backup(array $entries): array
    {
        $root = 'vehicle-image-reviews/backups/'.Str::uuid();
        $files = [];
        foreach ($entries as $entry) {
            $previous = $entry['previous'];
            $references = $previous['image'] ? [['disk' => 'public', 'path' => $previous['image']]] : [];
            foreach ($previous['image_conversions'] ?? [] as $conversion) {
                $references[] = ['disk' => $conversion['disk'] ?? 'public', 'path' => $conversion['path'] ?? ''];
            }
            foreach ($references as $ref) {
                $path = $ref['path'];
                if ($ref['disk'] !== 'public' || ! is_string($path) || $path === '' || str_contains($path, '..')
                    || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, ':')) {
                    throw new RuntimeException('Небезопасный путь резервной копии изображения.');
                }
                $key = $ref['disk'].':'.$path;
                if (isset($files[$key])) {
                    continue;
                }
                if (! Storage::disk('public')->exists($path)) {
                    $files[$key] = $ref + ['missing' => true];

                    continue;
                }
                $sha = hash_file('sha256', Storage::disk('public')->path($path));
                $target = $root.'/'.$sha.'.bin';
                $stream = Storage::disk('public')->readStream($path);
                try {
                    if (! is_resource($stream) || ! Storage::disk('local')->put($target, $stream)) {
                        throw new RuntimeException('Не удалось сохранить резервную копию изображения.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (! hash_equals($sha, hash_file('sha256', Storage::disk('local')->path($target)))) {
                    throw new RuntimeException('Контрольная сумма резервной копии не совпала.');
                }
                $files[$key] = $ref + ['sha256' => $sha, 'backup' => $target];
            }
            $this->assertSnapshotCurrent($entry);
        }

        return ['directory' => $root, 'files' => $files];
    }

    private function assertSnapshotCurrent(array $entry): void
    {
        $g = VehicleGeneration::with('model.make')->findOrFail($entry['id']);
        if (! hash_equals($entry['fingerprint'], $this->quality->fingerprint($g))) {
            throw new RuntimeException('Изображение изменилось во время подготовки резервной копии.');
        }
    }

    private function assertBackup(array $plan): void
    {
        if (! $plan['source'] || ! is_array($plan['backup']['files'] ?? null)) {
            throw new RuntimeException('Нет исходного Excel или резервной копии.');
        }
        foreach ($plan['backup']['files'] as $file) {
            if ($file['missing'] ?? false) {
                continue;
            }
            if (! Storage::disk('local')->exists($file['backup'])
                || ! hash_equals($file['sha256'], hash_file('sha256', Storage::disk('local')->path($file['backup'])))) {
                throw new RuntimeException('Резервная копия изображения отсутствует или повреждена.');
            }
        }
    }

    private function assertCurrent(array $entry): VehicleGeneration
    {
        $g = VehicleGeneration::with('model.make')->findOrFail($entry['id']);
        if (app(CatalogImportProtections::class)->protectsVehicle('generation', $g->id)
            || ! hash_equals($entry['fingerprint'], $this->quality->fingerprint($g)) || ($g->image && $g->image_source_url === null)) {
            throw new RuntimeException('Изображение #'.$entry['id'].' или его защита изменились; нужен новый план.');
        }

        return $g;
    }

    private function completionPath(array $plan, array $entry): string
    {
        return 'vehicle-image-reviews/applied/'.$plan['seal'].'/'.$entry['id'].'.json';
    }

    private function completed(array $plan, array $entry): bool
    {
        $path = $this->completionPath($plan, $entry);
        if (! Storage::disk('local')->exists($path)) {
            return false;
        }
        $doc = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($this->seal($doc['receipt']), $doc['seal'])) {
            throw new RuntimeException('Журнал повторной обработки изменён.');
        }
        if ($doc['receipt']['plan_seal'] !== $plan['seal'] || $doc['receipt']['before'] !== $entry['fingerprint'] || $doc['receipt']['id'] !== $entry['id']) {
            throw new RuntimeException('Журнал относится к другому плану изображений.');
        }
        $g = VehicleGeneration::with('model.make')->findOrFail($entry['id']);
        if (! hash_equals($doc['receipt']['fingerprint'], $this->quality->fingerprint($g))) {
            throw new RuntimeException('Завершённое изображение изменилось после обработки.');
        }

        return true;
    }

    private function writeCompletion(array $plan, array $entry, array $receipt): void
    {
        if (! Storage::disk('local')->put($this->completionPath($plan, $entry), json_encode(['receipt' => $receipt, 'seal' => $this->seal($receipt)], JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Не удалось записать журнал. Обработка остановлена; перед продолжением нужен новый просмотр состояния.');
        }
    }

    private function database(): array
    {
        return [app()->environment(), DB::connection()->getConfig('host'), DB::connection()->getDatabaseName(),
            DB::getDriverName() === 'mysql' ? DB::selectOne('SELECT @@server_uuid AS uuid')->uuid : 'sqlite'];
    }

    private function seal(array $body): string
    {
        return hash_hmac('sha256', json_encode($body, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
