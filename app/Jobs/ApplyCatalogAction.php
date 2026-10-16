<?php

namespace App\Jobs;

use App\Services\Catalog\CatalogActions;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplyCatalogAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(public int $id, public int $cursor, public bool $publication = false) {}

    public function handle(CatalogActions $actions): void
    {
        try {
            if ($this->publication) {
                $actions->publish($this->id);
            } else {
                $actions->batch($this->id, $this->cursor);
            }
        } catch (Throwable $e) {
            if (! $this->publication) {
                if (DB::transactionLevel() === 0 && app(LostConnectionDetector::class)->causedByLostConnection($e)) {
                    DB::purge();
                }
                try {
                    $actions->failure($this->id, $this->cursor, $e);
                } catch (Throwable $diagnostic) {
                    report($diagnostic);
                }
            }
            throw $e;
        }
        $actions->dispatchPending();
    }
}
