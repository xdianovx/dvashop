<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use App\Services\Import\ImportImageDownloader;
use App\Services\Import\ImportImageTasks;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\Search\CatalogSearchSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DownloadVehicleGenerationImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 420;

    public bool $failOnTimeout = true;

    public ?string $imageTaskToken = null;

    public int $imageTaskEpoch = 0;

    // Keep property defaults: historical serialized jobs omit these when empty/null.
    public array $alternativeUrls = [];

    public ?int $retryOfLogId = null;

    public function __construct(
        public int $vehicleGenerationId,
        public string $url,
        public ?int $importRunId = null,
        array $alternativeUrls = [],
        ?int $retryOfLogId = null,
    ) {
        $this->alternativeUrls = $alternativeUrls;
        $this->retryOfLogId = $retryOfLogId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('vehicle-image-'.$this->vehicleGenerationId))->releaseAfter(30)->expireAfter(450)];
    }

    public function handle(ImportImageDownloader $downloader, ImportLogger $logger, ImportStatusService $statusService): void
    {
        $tasks = app(ImportImageTasks::class);
        $claim = $tasks->start($this, 'vehicle');
        if ($claim['state'] === 'parked') {
            $this->delete();

            return;
        }
        if ($claim['state'] === 'busy') {
            $this->release(30);

            return;
        }
        if ($claim['state'] !== 'run') {
            return;
        }
        $this->imageTaskToken = $claim['token'];
        $run = $this->importRunId ? ImportRun::find($this->importRunId) : null;
        $generation = VehicleGeneration::find($this->vehicleGenerationId);
        if (! $generation) {
            $tasks->complete($this, 'vehicle', false, ['error' => 'Поколение авто для изображения не найдено.'], $this->imageTaskToken);

            return;
        }
        $success = false;
        $details = [];
        try {
            if ($downloader->canReuseVehicleImage($generation)) {
                $success = true;
                $details = ['selected_url' => $generation->image_source_url, 'reused' => true];
            } else {
                $failures = [];
                foreach (array_slice(array_values(array_unique([$this->url, ...$this->alternativeUrls])), 0, 3) as $url) {
                    try {
                        $download = fn () => $downloader->downloadVehicleGenerationImage($generation, $url);
                        $run?->isSnapshotCatalogImport() ? CatalogSearchSync::withoutSyncing($download) : $download();
                        $success = true;
                        $details = ['selected_url' => $url, 'failed_sources' => $failures];
                        break;
                    } catch (Throwable $error) {
                        $failures[] = ['url' => $url, 'error' => $error->getMessage()];
                    }
                }
                if (! $success) {
                    $details = ['failed_sources' => $failures];
                }
            }
        } catch (Throwable $e) {
            $details = ['error' => $e->getMessage()];
        }
        // A failed result transaction must escape to the queue, never become a download failure.
        if (! $success && $tasks->retry($this, 'vehicle', $details)) {
            return;
        }
        $tasks->complete($this, 'vehicle', $success, $details, $this->imageTaskToken);
    }

    public function failed(Throwable $e): void
    {
        app(ImportImageTasks::class)->complete($this, 'vehicle', false, ['error' => $e->getMessage(), 'attempts_exhausted' => true]);
    }
}
