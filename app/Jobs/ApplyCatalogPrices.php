<?php

namespace App\Jobs;

use App\Services\Catalog\CatalogBulkPrices;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplyCatalogPrices implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(public int $operationId, public ?int $expectedCursor = null) {}

    public function failed(?Throwable $error): void
    {
        $this->recordFailure(app(CatalogBulkPrices::class), $error ?? new \RuntimeException('Задание остановлено очередью.'));
    }

    public function handle(CatalogBulkPrices $prices): void
    {
        try {
            $prices->batch($this->operationId, $this->expectedCursor);
        } catch (Throwable $e) {
            $this->recordFailure($prices, $e);
            throw $e;
        }
        $prices->dispatchPending();
    }

    private function recordFailure(CatalogBulkPrices $prices, Throwable $error): void
    {
        // A lost connection can leave the PDO transaction flag set after Laravel resets
        // its transaction counter. Discard only that broken connection, never an outer transaction.
        if (DB::transactionLevel() === 0 && app(LostConnectionDetector::class)->causedByLostConnection($error)) {
            DB::purge();
        }
        try {
            $prices->recordFailure($this->operationId, $error, $this->expectedCursor);
        } catch (Throwable $diagnosticError) {
            // MySQL may still be offline. The committed delivery lease remains recoverable
            // by the scheduler; preserve the original error for the queue's retry policy.
            report($diagnosticError);
        }
    }
}
