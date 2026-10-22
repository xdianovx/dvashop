<?php

namespace App\Services\Import;

use App\Enums\ImportRunStatus;
use App\Jobs\DownloadProductImageJob;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable outbox/leases in the existing import log. All mutations lock the owning run first. */
class ImportImageTasks
{
    public const MAX_ATTEMPTS = 3;

    public function spec(object $job, string $kind): array
    {
        $id = $kind === 'vehicle' ? $job->vehicleGenerationId : $job->productId;
        $retry = $kind === 'vehicle' ? ($job->retryOfLogId ?? null) : null;

        return ['key' => $kind.':'.$id.':'.hash('sha256', $job->url).':'.($retry ?? 'initial'),
            'kind' => $kind, 'entity_id' => $id, 'url' => $job->url,
            'alternative_urls' => $job->alternativeUrls ?? [], 'retry_of_log_id' => $retry];
    }

    public function enqueue(ImportRun $run, string $kind, int $id, string $url, array $alternatives = []): bool
    {
        return DB::transaction(function () use ($run, $kind, $id, $url, $alternatives): bool {
            $run = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->isTerminal()) {
                return false;
            }
            $job = $kind === 'vehicle' ? new DownloadVehicleGenerationImageJob($id, $url, $run->id, $alternatives) : new DownloadProductImageJob($id, $url, $run->id);
            $spec = $this->spec($job, $kind);
            if ($this->task($run, $spec['key']) || $this->result($run, $spec)) {
                return false;
            }
            $this->journal($run, $spec);
            if ($kind === 'vehicle' && in_array($id.':'.hash('sha256', $url), $run->queued_vehicle_image_keys ?? [], true)) {
                // Adopt a legacy reservation without incrementing or delivering it twice.
                return false;
            }
            $changes = ['queued_images' => $run->queued_images + 1];
            if ($kind === 'vehicle') {
                $changes['queued_vehicle_image_keys'] = array_values(array_unique([...($run->queued_vehicle_image_keys ?? []), $id.':'.hash('sha256', $url)]));
            }
            $run->forceFill($changes)->save();
            $this->dispatch($job);

            return true;
        });
    }

    public function dispatch(object $job): void
    {
        $connection = config('queue.default');
        $retry = config('queue.connections.'.$connection.'.retry_after');
        if ($retry !== null && $retry <= $job->timeout) {
            throw new RuntimeException('retry_after должен превышать timeout задания изображения.');
        }
        // DB queue insertion shares the row transaction. Other backends use the durable outbox after commit.
        $job->onQueue('imports-images');
        if (config('queue.connections.'.$connection.'.driver') !== 'database'
            || (config('queue.connections.'.$connection.'.connection') ?? config('database.default')) !== config('database.default')) {
            $job->afterCommit();
        }
        Bus::dispatch($job);
    }

    public function start(object $job, string $kind): array
    {
        if ($job->importRunId === null) {
            return ['state' => 'run', 'token' => null];
        }

        return DB::transaction(function () use ($job, $kind): array {
            $run = ImportRun::whereKey($job->importRunId)->lockForUpdate()->first();
            if (! $run || ($run->isTerminal() && ! ($run->status === ImportRunStatus::Done && ($job->retryOfLogId ?? null)))) {
                return ['state' => 'terminal'];
            }
            $spec = $this->spec($job, $kind);
            if ($this->result($run, $spec)) {
                return ['state' => 'finished'];
            }
            $log = $this->task($run, $spec['key']) ?? $this->journal($run, $spec);
            $task = $log->context['image_task'];
            if ($job->imageTaskEpoch !== ($task['epoch'] ?? 0)) {
                return ['state' => 'terminal'];
            }
            if ($run->status === ImportRunStatus::Paused) {
                $this->updateTask($log, $task, ['state' => 'parked']);

                return ['state' => 'parked'];
            }
            if (($task['lease_until'] ?? 0) > now()->timestamp) {
                return ['state' => 'busy'];
            }
            if (($task['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
                $this->complete($job, $kind, false, ['error' => 'Исчерпаны попытки выполнения изображения.']);

                return ['state' => 'finished'];
            }
            $token = (string) Str::uuid();
            $this->updateTask($log, $task, ['state' => 'running', 'token' => $token,
                'attempts' => ($task['attempts'] ?? 0) + 1, 'lease_until' => now()->timestamp + $job->timeout + 60,
                'queue_uuid' => $job->job?->uuid(), 'queue_attempt' => $job->job?->attempts(), 'last_activity' => now()->timestamp]);

            return ['state' => 'run', 'token' => $token];
        });
    }

    /** A result and its counter commit together, even when pause arrives during the download. */
    public function complete(object $job, string $kind, bool $success, array $details, ?string $token = null): void
    {
        if ($job->importRunId === null) {
            return;
        }
        DB::transaction(function () use ($job, $kind, $success, $details, $token): void {
            $run = ImportRun::whereKey($job->importRunId)->lockForUpdate()->first();
            if (! $run || ($run->isTerminal() && ! ($run->status === ImportRunStatus::Done && ($job->retryOfLogId ?? null)))) {
                return;
            }
            $spec = $this->spec($job, $kind);
            if ($this->result($run, $spec)) {
                return;
            }
            $log = $this->task($run, $spec['key']) ?? $this->journal($run, $spec);
            $task = $log->context['image_task'];
            if ($job->imageTaskEpoch !== ($task['epoch'] ?? 0)) {
                return;
            }
            if ($token !== null && ($task['token'] ?? null) !== $token) {
                return;
            }
            if ($token === null && ($task['lease_until'] ?? 0) > now()->timestamp
                && (! $job->job || ($task['queue_uuid'] ?? null) !== $job->job->uuid() || ($task['queue_attempt'] ?? null) !== $job->job->attempts())) {
                return;
            }
            $context = $details + ['image_task_result_key' => $spec['key'], $kind.'_image_result_key' => $this->legacyKey($spec),
                $kind === 'vehicle' ? 'vehicle_generation_id' : 'product_id' => $spec['entity_id'],
                'url' => $spec['url'], 'alternative_urls' => $spec['alternative_urls'],
                'retry_of_log_id' => $spec['retry_of_log_id'], 'result' => $success ? 'saved' : 'failed'];
            $logger = app(ImportLogger::class);
            $message = $kind === 'vehicle' ? ($success ? 'Изображение поколения авто сохранено' : 'Не удалось скачать изображение поколения авто')
                : ($success ? 'Изображение товара сохранено' : 'Не удалось скачать изображение товара');
            $success ? $logger->info($run, $message, $context) : $logger->warning($run, $message, $context);
            $this->updateTask($log, $task, ['state' => 'finished', 'result' => $success ? 'saved' : 'failed', 'lease_until' => null, 'token' => null]);
            if ($spec['retry_of_log_id'] === null) {
                $status = app(ImportStatusService::class);
                $success ? $status->imageProcessed($run) : $status->imageFailed($run);
            }
        });
    }

    public function retry(object $job, string $kind, array $details): bool
    {
        if ($job->importRunId === null || ! $job->job) {
            return false;
        }

        return DB::transaction(function () use ($job, $kind, $details): bool {
            $run = ImportRun::whereKey($job->importRunId)->lockForUpdate()->firstOrFail();
            $log = $this->task($run, $this->spec($job, $kind)['key']);
            $task = $log?->context['image_task'];
            if (! $task || $run->isTerminal() || ($task['token'] ?? null) !== $job->imageTaskToken || $task['attempts'] >= self::MAX_ATTEMPTS) {
                return false;
            }
            $this->updateTask($log, $task, ['state' => $run->status === ImportRunStatus::Paused ? 'parked' : 'expected',
                'lease_until' => null, 'token' => null, 'last_error' => $details, 'last_activity' => now()->timestamp]);
            if ($run->status === ImportRunStatus::Paused) {
                $job->delete();
            } else {
                $job->release(30);
            }

            return true;
        });
    }

    public function hasUnfinished(ImportRun $run): bool
    {
        if ($run->logs()->whereNotNull('context->image_task')->whereNull('context->image_task->retry_of_log_id')->where('context->image_task->state', '!=', 'finished')->exists()) {
            return true;
        }
        $keys = $run->queued_vehicle_image_keys ?? [];
        if ($keys === []) {
            return false;
        }
        $completed = $run->logs()->whereNotNull('context->vehicle_image_result_key')->pluck('context')->map(fn ($c) => $c['vehicle_image_result_key'])->all();

        return array_diff(array_map(fn ($key) => $key.':initial', $keys), $completed) !== [];
    }

    public function task(ImportRun $run, string $key): ?ImportLog
    {
        return $run->logs()->where('context->image_task_key', $key)->whereNotNull('context->image_task')->first();
    }

    public function journal(ImportRun $run, array $spec): ImportLog
    {
        return app(ImportLogger::class)->info($run, 'Ожидается результат задания изображения', ['image_task_key' => $spec['key'],
            'image_task' => $spec + ['state' => 'expected', 'epoch' => 0, 'attempts' => 0, 'recoveries' => 0, 'last_activity' => now()->timestamp]]);
    }

    public function updateTask(ImportLog $log, array $task, array $changes): void
    {
        $log->forceFill(['context' => ['image_task_key' => $task['key'], 'image_task' => array_replace($task, $changes)]])->save();
    }

    private function legacyKey(array $spec): string
    {
        return $spec['entity_id'].':'.hash('sha256', $spec['url']).':'.($spec['retry_of_log_id'] ?? 'initial');
    }

    private function result(ImportRun $run, array $spec): ?ImportLog
    {
        return $run->logs()->where(fn ($q) => $q->where('context->image_task_result_key', $spec['key'])
            ->orWhere('context->'.$spec['kind'].'_image_result_key', $this->legacyKey($spec)))->first();
    }
}
