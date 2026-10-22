<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\Product;
use App\Services\Import\ImportImageDownloader;
use App\Services\Import\ImportImageTasks;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\Search\CatalogSearchSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DownloadProductImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public ?string $imageTaskToken = null;

    public int $imageTaskEpoch = 0;

    public function __construct(public int $productId, public string $url, public ?int $importRunId = null) {}

    public function handle(ImportImageDownloader $downloader, ImportLogger $logger, ImportStatusService $statusService): void
    {
        $tasks = app(ImportImageTasks::class);
        $claim = $tasks->start($this, 'product');
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
        $product = Product::find($this->productId);
        if (! $product) {
            $tasks->complete($this, 'product', false, ['error' => 'Товар для изображения не найден.'], $this->imageTaskToken);

            return;
        }
        $success = false;
        $details = [];
        try {
            $download = fn () => $downloader->download($product, $this->url);
            $run?->isSnapshotCatalogImport() ? CatalogSearchSync::withoutSyncing($download) : $download();
            $success = true;
        } catch (Throwable $e) {
            $details = ['error' => $e->getMessage()];
        }
        if (! $success && $tasks->retry($this, 'product', $details)) {
            return;
        }
        $tasks->complete($this, 'product', $success, $details, $this->imageTaskToken);
    }

    public function failed(Throwable $e): void
    {
        app(ImportImageTasks::class)->complete($this, 'product', false, ['error' => $e->getMessage(), 'attempts_exhausted' => true]);
    }
}
