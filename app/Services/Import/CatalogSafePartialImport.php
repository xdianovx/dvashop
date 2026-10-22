<?php

namespace App\Services\Import;

use App\Enums\AdminPermission;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportContext;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\Catalog\CatalogPricingSchema;
use App\Services\Search\CatalogSearchPublication;
use App\Services\Search\CatalogSearchSync;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class CatalogSafePartialImport
{
    private ?CatalogImportIdentity $identity = null;

    private array $checkedProducts = [];

    public function preview(ImportRun $run): array
    {
        $this->mode($run);
        [$excel, $csv] = $this->files($run);
        $plan = DB::transaction(fn () => app(CatalogSafePartialPlan::class)->build($excel, $csv));
        $review = $plan['matrix_review'];

        return $review + ['mode' => 'safe_partial', 'run_id' => $run->id, 'csv_sha256' => $plan['files']['csv'],
            'excel_sha256' => $plan['files']['excel'], 'plan_version' => $plan['version'], 'plan_hash' => $plan['plan_hash'],
            'valid' => $plan['valid'], 'counts' => $plan['counts'], 'variant_counts' => $plan['variant_counts'],
            'skip_reasons' => $plan['skip_reasons'], 'installation' => $plan['installation'],
            'matrix_version' => $plan['matrix_version'], 'universe' => $plan['universe'],
            'schema_ready' => app(CatalogImportProtections::class)->ready(),
            'safe_confirmation' => $this->token($run, $plan['plan_hash'])];
    }

    public function confirm(ImportRun $run, User $user, string $token): ImportRun
    {
        abort_unless($user->canPerformAdminAction(AdminPermission::ManageCatalogImports), 403);

        return DB::transaction(function () use ($run, $user, $token): ImportRun {
            $this->ready();
            app(CatalogPriceGuard::class)->assertWritable();
            $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->mode($locked);
            if ($locked->status !== ImportRunStatus::Ready || $locked->initialized_at !== null) {
                $this->reject('Подтверждать можно только новый safe_partial.');
            }
            [$excel, $csv] = $this->files($locked);
            $plan = app(CatalogSafePartialPlan::class)->build($excel, $csv);
            if (! hash_equals($plan['matrix_version'], app(CatalogImportProtections::class)->matrixFingerprint(lock: true))
                || ! hash_equals($plan['universe'], CatalogSafePartialState::universe(lock: true))
                || ! hash_equals($plan['protection_version'], CatalogSafePartialState::protections(lock: true))) {
                $this->reject('Предпросмотр не соответствует текущему состоянию каталога.');
            }
            if (! $plan['valid'] || ! hash_equals($this->token($locked, $plan['plan_hash']), $token)) {
                $this->reject('План safe_partial неполон или устарел.');
            }
            $review = $this->compact($locked, $plan);
            if (DB::table('catalog_safe_partial_items')->where('import_run_id', $locked->id)->exists()) {
                $this->reject('План этого ImportRun уже подтверждён. Создайте новый ImportRun.');
            }
            foreach (array_chunk($plan['items'], 200) as $batch) {
                $records = [];
                foreach ($batch as $item) {
                    $payload = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $records[] = ['import_run_id' => $locked->id, 'incoming_key' => $item['expected']['proposed_import_key'],
                        'product_id' => $item['expected']['product_id'], 'fingerprint' => $item['fingerprint'],
                        'payload' => json_encode(['item' => $item, 'seal' => $this->seal($locked, $payload)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                }
                DB::table('catalog_safe_partial_items')->insert($records);
            }
            $this->validateItems($locked);
            app(CatalogImportProtections::class)->persist($locked->id, $plan['protections'], $plan['version'], $plan['plan_hash']);
            $review['protection_version'] = CatalogSafePartialState::protections(lock: true);
            $review['protection_seal'] = $this->seal($locked, json_encode([
                'plan_hash' => $plan['plan_hash'], 'protection_version' => $review['protection_version'],
            ], JSON_THROW_ON_ERROR));
            $locked->forceFill(['configuration_review' => $review, 'configuration_confirmed_hash' => $plan['files']['csv'],
                'configuration_confirmed_by' => $user->id, 'configuration_confirmed_at' => now()])->save();

            return $locked;
        });
    }

    public function assertPreview(ImportRun $run, array $review): string
    {
        $stored = $run->configuration_review;
        if (! $run->configuration_confirmed_at || ! $run->configuration_confirmed_by
            || ! ($review['valid'] ?? false) || ! is_array($stored)
            || ! hash_equals($stored['safe_confirmation'] ?? '', $review['safe_confirmation'] ?? '')) {
            $this->reject('Подтверждение safe_partial отсутствует или устарело.');
        }

        return $stored['safe_confirmation'];
    }

    /** Queue delivery is not consent. Every route must verify persisted approval. */
    public function start(ImportRun $run): ImportRun
    {
        CatalogSafePartialPlan::calculationBudget();

        return CatalogImportContext::run($run->id, fn () => DB::transaction(function () use ($run): ImportRun {
            $this->ready();
            app(CatalogPriceGuard::class)->assertWritable();
            $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->mode($locked);
            if ($locked->isTerminal()) {
                return $locked;
            }
            $this->approval($locked);
            if ($locked->initialized_at !== null) {
                $this->currentFilesAndMatrix($locked);
                if ($locked->status === ImportRunStatus::Paused) {
                    $locked->forceFill(['status' => ImportRunStatus::RunningRows, 'heartbeat_at' => now()])->save();
                }

                return $locked;
            }
            $review = $locked->configuration_review;
            if (ImportRun::whereKeyNot($locked->id)->whereIn('status', ['running', 'running_rows', 'paused', 'processing_images'])->exists()) {
                $this->reject('Сначала завершите другие импорты.');
            }
            if (! hash_equals($review['matrix_version'], app(CatalogImportProtections::class)->matrixFingerprint(lock: true))
                || ! hash_equals($review['universe'], CatalogSafePartialState::universe(lock: true))) {
                $this->reject('Состав каталога или матрица изменились после подтверждения.');
            }
            $this->validateItems($locked);
            DB::table('catalog_price_locks')->where('id', 1)->update(['safe_partial_run_id' => $locked->id]);
            $locked->forceFill(['status' => ImportRunStatus::RunningRows, 'started_at' => $locked->started_at ?? now(),
                'prepared_rows' => ['safe_partial_installing' => true], 'heartbeat_at' => now()])->save();
            [, $csv] = $this->files($locked);
            app(CatalogConfigurations::class)->install($csv, authorizedImportId: $locked->id);
            $reader = app(SpreadsheetReader::class);
            [$excel] = $this->files($locked);
            // Resolving headers is read-only; skipped rows never reach prepareDetailColumns.
            $headers = $reader->readMergedDetailHeaders($excel);
            $locked->forceFill(['initialized_at' => now(), 'total_rows' => $review['counts']['updates'] + $review['counts']['new'],
                'detail_columns' => $headers, 'archive_skipped' => true, 'archive_skip_reason' => 'safe_partial',
                'prepared_rows' => ['safe_partial' => true, 'last_item_id' => 0,
                    'matrix_version' => app(CatalogImportProtections::class)->matrixFingerprint(lock: true)]])->save();

            return $locked;
        }));
    }

    public function batch(ImportRun $run, int $cursor): bool
    {
        return CatalogSearchSync::withoutSyncing(fn () => CatalogImportContext::run($run->id, fn () => DB::transaction(function () use ($run, $cursor): bool {
            $this->ready();
            app(CatalogPriceGuard::class)->assertWritable();
            $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->mode($locked);
            if (! $locked->status?->isRowsRunning() || ! $locked->initialized_at) {
                return false;
            }
            $this->approval($locked);
            $progress = $locked->prepared_rows;
            if (($progress['last_item_id'] ?? 0) !== $cursor) {
                return false;
            }
            if ((int) DB::table('catalog_price_locks')->where('id', 1)->value('safe_partial_run_id') !== $locked->id) {
                $this->reject('Утрачено резервирование safe_partial.');
            }
            $this->currentFilesAndMatrix($locked);
            $records = DB::table('catalog_safe_partial_items')->where('import_run_id', $locked->id)->where('id', '>', $cursor)
                ->orderBy('id')->limit(max(1, min(30, $locked->chunk_size)))->lockForUpdate()->get();
            $this->identity = app(CatalogImportIdentity::class);
            $this->primeProducts($records);
            foreach ($records as $record) {
                if ($record->result !== null) {
                    $this->reject('Некорректный курсор уже обработанного товара.');
                }
                $item = $this->checkItem($locked, $record);
                app(ImportProductFactory::class)->applySafePartial($locked, $item);
                $product = Product::where('import_key', $item['expected']['import_key'])->firstOrFail();
                $after = CatalogSafePartialState::product($product);
                DB::table('catalog_safe_partial_items')->where('id', $record->id)->update([
                    'product_id' => $product->id, 'result' => json_encode(['fingerprint' => $after, 'product_id' => $product->id], JSON_THROW_ON_ERROR)]);
                $progress['last_item_id'] = $record->id;
            }
            $done = ! DB::table('catalog_safe_partial_items')->where('import_run_id', $locked->id)->whereNull('result')->exists();
            $locked->forceFill(['processed_rows' => $locked->processed_rows + $records->count(),
                'current_row' => $locked->current_row + $records->count(), 'prepared_rows' => $progress, 'heartbeat_at' => now(),
                ...($done ? ['status' => ImportRunStatus::Done, 'finished_at' => now()] : [])])->save();
            if ($done) {
                DB::table('catalog_price_locks')->where('id', 1)->where('safe_partial_run_id', $locked->id)->update(['safe_partial_run_id' => null]);
                app(CatalogSearchPublication::class)->record($locked);
            }

            return ! $done;
        })));
    }

    public function cancel(ImportRun $run): ImportRun
    {
        return CatalogImportContext::run($run->id, fn () => DB::transaction(function () use ($run): ImportRun {
            app(CatalogPriceGuard::class)->lock();
            $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isTerminal()) {
                $locked->forceFill(['status' => ImportRunStatus::Canceled, 'finished_at' => now()])->save();
            }
            DB::table('catalog_price_locks')->where('id', 1)->where('safe_partial_run_id', $locked->id)->update(['safe_partial_run_id' => null]);

            return $locked;
        }));
    }

    public function assertInstallationOwner(ImportRun $run): void
    {
        $this->approval($run);
        if (CatalogImportContext::owner() !== $run->id || ! ($run->prepared_rows['safe_partial_installing'] ?? false)
            || (int) DB::table('catalog_price_locks')->where('id', 1)->value('safe_partial_run_id') !== $run->id) {
            $this->reject('Установка CSV вне подтверждённого safe_partial запрещена.');
        }
    }

    public function assertItemOwner(ImportRun $run): void
    {
        if ($run->import_mode !== 'safe_partial' || CatalogImportContext::owner() !== $run->id) {
            $this->reject('Нет контекста подтверждённой порции safe_partial.');
        }
    }

    private function checkItem(ImportRun $run, object $record): array
    {
        $wrapped = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($wrapped['item'] ?? null) || ! is_string($wrapped['seal'] ?? null)) {
            $this->reject('Повреждён подтверждённый товар safe_partial.');
        }
        $item = $wrapped['item'];
        $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (! hash_equals($wrapped['seal'], $this->seal($run, $json)) || $record->fingerprint !== $item['fingerprint']
            || $record->incoming_key !== $item['expected']['proposed_import_key']) {
            $this->reject('Повреждена подтверждённая область safe_partial.');
        }
        $expected = $item['expected'];
        if ($expected['product_id']) {
            $p = $this->checkedProducts[$expected['product_id']] ?? null;
            if (! $p || app(CatalogImportProtections::class)->protects($p->id)
                || ! hash_equals($item['fingerprint'], CatalogSafePartialState::product($p))) {
                $this->reject('Товар изменился после подтверждения: '.$expected['import_key']);
            }
        } else {
            if (Product::withTrashed()->where('import_key', $expected['import_key'])->exists()) {
                $this->reject('Новый ключ уже занят.');
            }
        }
        $r = ($this->identity ??= app(CatalogImportIdentity::class))->resolve($item['row'], $expected['part_type_full_slug'], 'catalog');
        if ($r['error'] !== null || $r['import_key'] !== $expected['import_key'] || $r['product_id'] !== $expected['product_id']) {
            $this->reject('Историческое соответствие устарело.');
        }

        return $item;
    }

    private function primeProducts($records): void
    {
        $this->checkedProducts = Product::with(['variants' => fn ($q) => $q->orderBy('id')->lockForUpdate(),
            'variants.optionValues' => fn ($q) => $q->lockForUpdate(),
            'fitments' => fn ($q) => $q->lockForUpdate(), 'images' => fn ($q) => $q->lockForUpdate()])
            ->whereIn('id', $records->pluck('product_id')->filter())->lockForUpdate()->get()->keyBy('id')->all();
    }

    private function validateItems(ImportRun $run): void
    {
        $this->identity = app(CatalogImportIdentity::class);
        DB::table('catalog_safe_partial_items')->where('import_run_id', $run->id)->orderBy('id')
            ->chunkById(100, function ($records) use ($run): void {
                $this->primeProducts($records);
                foreach ($records as $record) {
                    $this->checkItem($run, $record);
                }
            });
    }

    private function approval(ImportRun $run): void
    {
        $this->mode($run);
        $r = $run->configuration_review;
        $this->files($run);
        if (! $run->configuration_confirmed_at || ! $run->configuration_confirmed_by || ! is_array($r)
            || ! ($r['valid'] ?? false) || ($r['plan_version'] ?? null) !== CatalogSafePartialPlan::VERSION
            || ! hash_equals($this->token($run, $r['plan_hash'] ?? ''), $r['safe_confirmation'] ?? '')
            || DB::table('catalog_safe_partial_items')->where('import_run_id', $run->id)->count() !== ($r['counts']['updates'] + $r['counts']['new'])) {
            $this->reject('Нет полного подтверждённого плана safe_partial.');
        }
        $scope = json_encode(['plan_hash' => $r['plan_hash'], 'protection_version' => $r['protection_version'] ?? ''], JSON_THROW_ON_ERROR);
        if (! hash_equals($this->seal($run, $scope), $r['protection_seal'] ?? '')
            || ! hash_equals($r['protection_version'] ?? '', CatalogSafePartialState::protections(lock: true))) {
            $this->reject('Постоянная защищённая область изменилась после подтверждения.');
        }
    }

    private function currentFilesAndMatrix(ImportRun $run): void
    {
        $this->files($run);
        if (! hash_equals($run->prepared_rows['matrix_version'] ?? '', app(CatalogImportProtections::class)->matrixFingerprint(lock: true))) {
            $this->reject('Матрица изменилась между порциями.');
        }
    }

    private function compact(ImportRun $run, array $p): array
    {
        return ['mode' => 'safe_partial', 'plan_version' => $p['version'], 'plan_hash' => $p['plan_hash'],
            'safe_confirmation' => $this->token($run, $p['plan_hash']), 'valid' => $p['valid'], 'counts' => $p['counts'],
            'variant_counts' => $p['variant_counts'], 'matrix_version' => $p['matrix_version'], 'universe' => $p['universe'],
            'csv_sha256' => $p['files']['csv'], 'excel_sha256' => $p['files']['excel']];
    }

    private function files(ImportRun $run): array
    {
        $paths = [Storage::disk('local')->path($run->stored_path), Storage::disk('local')->path($run->configuration_path ?? '')];
        foreach ([$run->file_hash, $run->configuration_hash] as $i => $hash) {
            if (! is_string($hash) || ! is_file($paths[$i]) || ! hash_equals($hash, (string) hash_file('sha256', $paths[$i]))) {
                $this->reject('Входные файлы safe_partial изменились или недоступны.');
            }
        }

        return $paths;
    }

    private function token(ImportRun $run, string $hash): string
    {
        return hash_hmac('sha256', json_encode([$run->id, $run->stored_path, $run->configuration_path,
            $run->file_hash, $run->configuration_hash, $hash], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function seal(ImportRun $run, string $payload): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };
        $canonical = json_encode($sort(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $run->id.':'.$canonical, config('app.key'));
    }

    private function mode(ImportRun $run): void
    {
        if ($run->import_mode !== 'safe_partial' || $run->type !== 'catalog') {
            $this->reject('Нужен явный режим safe_partial источника catalog.');
        }
    }

    private function ready(): void
    {
        if (! app(CatalogImportProtections::class)->ready() || ! app(CatalogPricingSchema::class)->has('catalog_safe_partial_items')) {
            $this->reject('Сначала отдельно разверните миграцию safe_partial.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['safe_partial' => $message]);
    }
}
