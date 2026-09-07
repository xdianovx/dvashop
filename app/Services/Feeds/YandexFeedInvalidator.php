<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use App\Enums\ImportRunStatus;
use App\Jobs\RebuildYandexFeedJob;
use App\Models\ImportRun;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class YandexFeedInvalidator
{
    private const TABLES = [
        'products', 'product_variants', 'product_categories', 'product_images', 'part_types',
        'product_fitments', 'vehicle_generations', 'vehicle_models', 'vehicle_makes',
        'product_option_values', 'product_option_groups', 'product_variant_option_values',
        'product_characteristics', 'shop_settings', 'import_runs',
    ];

    public function __construct(private readonly YandexFeedState $state) {}

    public function queryExecuted(QueryExecuted $event): void
    {
        if (! config('yandex-feed.enabled')) {
            return;
        }
        // Includes bulk archive, pivot sync and saveQuietly gallery/import writes.
        // No SQL values are parsed, logged or transmitted.
        if (! preg_match('/^\s*(?:insert(?:\s+or\s+ignore|\s+ignore)?\s+into|replace\s+into|update|delete\s+from)\s+[`"]?([a-z_]+)[`"]?/i', $event->sql, $matches)
            || ! in_array(strtolower($matches[1]), self::TABLES, true)) {
            return;
        }
        $event->connection->afterCommit(function (): void {
            try {
                $this->invalidate();
            } catch (Throwable $exception) {
                Log::error('Yandex feed invalidation failed.', ['error' => $exception->getMessage()]);
            }
        });
    }

    public function invalidate(): void
    {
        $this->state->locked(function (array &$state): void {
            $state['source_revision']++;
        });
        $this->dispatchIfNeeded();
    }

    public function importInProgress(): bool
    {
        if (ImportRun::query()->where('type', 'catalog')->whereIn('status', [
            ImportRunStatus::Running->value, ImportRunStatus::RunningRows->value,
            ImportRunStatus::ProcessingImages->value, ImportRunStatus::Paused->value,
        ])->exists()) {
            return true;
        }

        // A failed/canceled import may already have committed partial rows.
        // Keep the last valid feed until a later successful import reconciles them.
        $latest = ImportRun::query()->where('type', 'catalog')->whereNotNull('started_at')->latest('id')->first(['status']);

        return $latest !== null && in_array($latest->status, [ImportRunStatus::Failed, ImportRunStatus::Canceled], true);
    }

    public function dispatchIfNeeded(): void
    {
        if (! config('yandex-feed.enabled') || $this->importInProgress()) {
            return;
        }
        $connection = (string) config('yandex-feed.connection');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new RuntimeException('Yandex feed requires an asynchronous queue connection.');
        }
        $retryAfter = config('queue.connections.'.$connection.'.retry_after');
        if (is_numeric($retryAfter) && (int) $retryAfter <= RebuildYandexFeedJob::TIMEOUT) {
            throw new RuntimeException('Yandex feed queue retry_after must exceed its job timeout.');
        }
        $token = $this->state->locked(function (array &$state): ?string {
            if (($state['generated_revision'] >= $state['source_revision']
                && is_file($this->state->path())
                && is_file($this->state->gzipPath()))
                || $state['scheduled_until'] > time()) {
                return null;
            }
            $state['scheduled_token'] = bin2hex(random_bytes(12));
            $state['scheduled_until'] = time() + (int) config('yandex-feed.dispatch_lease_seconds');

            return $state['scheduled_token'];
        });
        if ($token !== null) {
            try {
                RebuildYandexFeedJob::dispatch($token)->onConnection($connection)
                    ->onQueue((string) config('yandex-feed.queue'))
                    ->delay(now()->addSeconds((int) config('yandex-feed.debounce_seconds')))->afterCommit();
            } catch (Throwable $exception) {
                $this->releaseDispatch($token);
                throw $exception;
            }
        }
    }

    public function releaseDispatch(string $token): void
    {
        $this->state->locked(function (array &$state) use ($token): void {
            if ($state['scheduled_token'] === $token) {
                $state['scheduled_token'] = null;
                $state['scheduled_until'] = 0;
            }
        });
    }
}
