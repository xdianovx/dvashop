<?php

namespace App\Services\Import;

use App\Enums\ImportRunStatus;
use App\Jobs\DownloadProductImageJob;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportRun;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Reconcile only image accounting/dispatch. Never replays rows, publication or archive. */
class ImportImageRecovery
{
    public function __construct(private readonly ImportImageTasks $tasks) {}

    public function inspect(ImportRun $run, bool $lockQueue = false): array
    {
        $run->refresh();
        $connection = config('queue.default');
        $cfg = config('queue.connections.'.$connection);
        if (($cfg['driver'] ?? null) !== 'database' || ($cfg['connection'] ?? config('database.default')) !== config('database.default')) {
            throw new RuntimeException('Сверка поддерживает только database queue на том же соединении; неизвестное состояние очереди не считается пустой очередью.');
        }
        $expected = [];
        $results = [];
        $conflicts = [];
        foreach ($run->queued_vehicle_image_keys ?? [] as $key) {
            if (! preg_match('/^(\d+):([a-f0-9]{64})$/', $key, $m)) {
                $conflicts[] = 'invalid_dispatch_key';

                continue;
            }
            $expected['vehicle:'.$key.':initial'] = ['key' => 'vehicle:'.$key.':initial', 'kind' => 'vehicle', 'entity_id' => (int) $m[1],
                'url' => null, 'alternative_urls' => [], 'retry_of_log_id' => null, 'attempts' => 0, 'recoveries' => 0,
                'last_activity' => $run->heartbeat_at?->timestamp ?? now()->timestamp];
        }
        foreach ($run->logs()->orderBy('id')->get(['id', 'context']) as $log) {
            $c = $log->context ?? [];
            if (isset($c['image_task']) && ($c['image_task']['retry_of_log_id'] ?? null) === null) {
                $expected[$c['image_task']['key']] = $c['image_task'];
            }
            if (($c['retry_of_log_id'] ?? null) !== null) {
                continue;
            }
            $key = $c['image_task_result_key'] ?? (isset($c['vehicle_image_result_key']) ? 'vehicle:'.$c['vehicle_image_result_key'] : (isset($c['product_image_result_key']) ? 'product:'.$c['product_image_result_key'] : null));
            if ($key === null) {
                continue;
            }
            if (! in_array($c['result'] ?? null, ['saved', 'failed'], true) || isset($results[$key])) {
                $conflicts[] = 'ambiguous_receipt:'.$key;

                continue;
            }
            $results[$key] = ['id' => $log->id, 'result' => $c['result']];
        }
        if (count($expected) !== $run->queued_images) {
            $conflicts[] = 'expected_scope_does_not_match_queued_images';
        }
        if (array_diff(array_keys($results), array_keys($expected))) {
            $conflicts[] = 'receipt_outside_expected_scope';
        }
        $queue = [];
        $query = DB::table($cfg['table'] ?? 'jobs')->whereIn('queue', ['default', 'imports', 'imports-images']);
        if ($lockQueue) {
            $query->lockForUpdate();
        }
        foreach ($query->get(['id', 'queue', 'payload', 'reserved_at', 'available_at', 'attempts']) as $row) {
            $p = json_decode($row->payload, true);
            if (! is_array($p)) {
                $conflicts[] = 'unreadable_queue_payload';

                continue;
            }
            if (! in_array($p['displayName'] ?? null, [DownloadVehicleGenerationImageJob::class, DownloadProductImageJob::class], true)) {
                continue;
            }
            try {
                $job = unserialize($p['data']['command'] ?? '', ['allowed_classes' => [DownloadVehicleGenerationImageJob::class, DownloadProductImageJob::class]]);
                if (! $job instanceof DownloadVehicleGenerationImageJob && ! $job instanceof DownloadProductImageJob) {
                    throw new RuntimeException('Unknown job');
                }
                if ($job->importRunId !== $run->id || ($job->retryOfLogId ?? null) !== null) {
                    continue;
                }
                $spec = $this->tasks->spec($job, $job instanceof DownloadProductImageJob ? 'product' : 'vehicle');
                $queue[$spec['key']][] = ['id' => $row->id, 'reserved_at' => $row->reserved_at, 'available_at' => $row->available_at, 'attempts' => $row->attempts];
            } catch (\Throwable) {
                $conflicts[] = 'unreadable_image_job';
            }
        }
        if (array_diff(array_keys($queue), array_keys($expected))) {
            $conflicts[] = 'queued_image_outside_expected_scope';
        }
        $states = [];
        $missingUrls = [];
        foreach ($expected as $key => $task) {
            if (isset($results[$key])) {
                $states[$key] = 'finished';

                continue;
            }
            if (($task['state'] ?? null) === 'finished') {
                $conflicts[] = 'terminal_task_without_receipt:'.$key;
            }
            $reserved = array_filter($queue[$key] ?? [], fn ($item) => $item['reserved_at'] !== null
                && now()->timestamp < $item['reserved_at'] + (int) $cfg['retry_after']);
            if (($task['lease_until'] ?? 0) > now()->timestamp || $reserved !== []) {
                $states[$key] = 'running';
            } elseif (isset($queue[$key])) {
                $states[$key] = 'queued';
            } elseif (($task['state'] ?? null) === 'parked' || ($task['last_activity'] ?? now()->timestamp) <= now()->timestamp - max(660, (int) $cfg['retry_after'])) {
                $states[$key] = 'lost';
                if (! $task['url']) {
                    $missingUrls[$key] = $task;
                }
            } else {
                $states[$key] = 'awaiting_lease_or_queue_visibility';
            }
        }
        if ($missingUrls !== []) {
            $path = Storage::disk('local')->path($run->stored_path);
            if (! is_file($path) || ! hash_equals((string) $run->file_hash, hash_file('sha256', $path))) {
                $conflicts[] = 'original_source_missing_or_changed';
            } else {
                foreach (app(SpreadsheetReader::class)->catalogImageRows($path) as $entry) {
                    if (! $entry['row']) {
                        continue;
                    }
                    $urls = $entry['row']['_photo_urls'];
                    foreach ($missingUrls as $key => $task) {
                        if (! isset($urls[0]) || ! str_ends_with($key, ':'.hash('sha256', $urls[0]).':initial')) {
                            continue;
                        }
                        $identity = app(CatalogImportIdentity::class)->vehicle($entry['row']);
                        if ($identity['error'] === null && $identity['generation_id'] === $task['entity_id']) {
                            $expected[$key]['url'] = $urls[0];
                            $expected[$key]['alternative_urls'] = array_slice($urls, 1, 2);
                            unset($missingUrls[$key]);
                        }
                    }
                }
            }
            foreach (array_keys($missingUrls) as $key) {
                $conflicts[] = 'cannot_prove_original_url:'.$key;
            }
        }
        $plan = ['run_id' => $run->id, 'database' => [DB::connection()->getConfig('host'), DB::connection()->getDatabaseName(),
            DB::getDriverName() === 'mysql' ? DB::selectOne('SELECT @@server_uuid AS uuid')->uuid : 'sqlite'],
            'status' => $run->status->value, 'rows' => [$run->processed_rows, $run->total_rows],
            'before' => ['queued' => $run->queued_images, 'processed' => $run->processed_images, 'failed' => $run->failed_images],
            'expected' => $expected, 'results' => $results, 'queue' => $queue, 'states' => $states,
            'counts' => array_count_values($states), 'conflicts' => array_values(array_unique($conflicts)),
            'after' => ['processed' => count(array_filter($results, fn ($r) => $r['result'] === 'saved')),
                'failed' => count(array_filter($results, fn ($r) => $r['result'] === 'failed'))]];
        $plan['confirmation'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    public function recover(ImportRun $run, ?string $confirmation = null): array
    {
        return DB::transaction(function () use ($run, $confirmation): array {
            $run = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $plan = $this->inspect($run, true);
            if ($confirmation !== null && ! hash_equals($plan['confirmation'], $confirmation)) {
                throw new RuntimeException('Состояние импорта или очереди изменилось: повторите предварительную сверку.');
            }
            if ($plan['conflicts'] !== []) {
                throw new RuntimeException('Сверка неоднозначна: '.implode(', ', $plan['conflicts']));
            }
            if ($run->isTerminal() || $run->status !== ImportRunStatus::ProcessingImages || $run->processed_rows !== $run->total_rows) {
                throw new RuntimeException('Восстановление разрешено только после строк в processing_images; завершённый импорт не переоткрывается.');
            }
            // Counters are derived from proven unique receipts, never guessed from queue emptiness.
            $run->forceFill(['processed_images' => $plan['after']['processed'], 'failed_images' => $plan['after']['failed']])->save();
            $journals = $run->logs()->whereNotNull('context->image_task')->get()->keyBy(fn ($log) => $log->context['image_task']['key']);
            foreach ($plan['states'] as $key => $state) {
                if ($state === 'finished' && ($journal = $journals->get($key)) && $journal->context['image_task']['state'] !== 'finished') {
                    // An old worker can leave a valid legacy receipt after a new producer adopted its reservation.
                    $this->tasks->updateTask($journal, $journal->context['image_task'], ['state' => 'finished',
                        'result' => $plan['results'][$key]['result'], 'lease_until' => null, 'token' => null]);
                }
                if ($state !== 'lost') {
                    continue;
                }
                $task = $plan['expected'][$key];
                $job = $task['kind'] === 'vehicle' ? new DownloadVehicleGenerationImageJob($task['entity_id'], $task['url'], $run->id, $task['alternative_urls'])
                    : new DownloadProductImageJob($task['entity_id'], $task['url'], $run->id);
                $journal = $journals->get($key) ?? $this->tasks->journal($run, $task);
                $job->imageTaskEpoch = $task['epoch'] ?? 0;
                if (($task['attempts'] ?? 0) >= ImportImageTasks::MAX_ATTEMPTS || ($task['recoveries'] ?? 0) >= ImportImageTasks::MAX_ATTEMPTS) {
                    $this->tasks->complete($job, $task['kind'], false, ['error' => 'Исчерпаны попытки выполнения или восстановления потерянного задания.', 'recovered' => true]);
                } else {
                    $job->imageTaskEpoch++;
                    $this->tasks->updateTask($journal, $task, ['state' => 'expected', 'recoveries' => ($task['recoveries'] ?? 0) + 1,
                        'epoch' => $job->imageTaskEpoch, 'lease_until' => null, 'token' => null, 'last_activity' => now()->timestamp]);
                    $this->tasks->dispatch($job);
                }
            }
            app(ImportStatusService::class)->finishImagesIfComplete($run->fresh());
            app(ImportLogger::class)->info($run->fresh(), 'Сверены результаты заданий изображений', ['before' => $plan['before'], 'receipts' => $plan['after'], 'states' => $plan['counts']]);

            return $this->inspect($run->fresh());
        });
    }
}
