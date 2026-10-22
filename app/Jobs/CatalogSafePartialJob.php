<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Services\Import\CatalogSafePartialImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class CatalogSafePartialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $importRunId, public ?int $cursor = null) {}

    public function handle(): void
    {
        $run = ImportRun::findOrFail($this->importRunId);
        if ($run->import_mode !== 'safe_partial' || $run->isTerminal() || $run->status === ImportRunStatus::Paused) {
            return;
        }
        $service = app(CatalogSafePartialImport::class);
        try {
            if ($this->cursor === null) {
                $run = $service->start($run);
                $cursor = (int) ($run->prepared_rows['last_item_id'] ?? 0);
            } else {
                $cursor = $this->cursor;
            }
            if ($service->batch($run, $cursor)) {
                $run->refresh();
                self::dispatch($run->id, $run->prepared_rows['last_item_id'])->onQueue('imports');
            }
        } catch (Throwable $e) {
            $run->refresh();
            if (! $run->isTerminal()) {
                // Preserve the reservation and persistent protections. Resume requires revalidation.
                $run->forceFill(['status' => ImportRunStatus::Paused, 'last_error' => $e->getMessage(), 'heartbeat_at' => now()])->save();
            }
            report($e);
        }
    }
}
