<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\Product;
use App\Services\Import\ImportImageDownloader;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DownloadProductImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $productId,
        public string $url,
        public ?int $importRunId = null,
    ) {}

    public function handle(ImportImageDownloader $downloader, ImportLogger $logger, ImportStatusService $statusService): void
    {
        $run = $this->importRun();

        if ($run?->status === ImportRunStatus::Canceled || $run?->status === ImportRunStatus::Failed) {
            return;
        }

        try {
            $product = Product::query()->find($this->productId);

            if (! $product instanceof Product) {
                throw new \RuntimeException('Товар для изображения не найден.');
            }

            $downloader->download($product, $this->url);

            if ($run !== null) {
                $statusService->imageProcessed($run);
            }
        } catch (Throwable $e) {
            $this->recordFailure($logger, $statusService, $e);
        }
    }

    public function failed(Throwable $e): void
    {
        $this->recordFailure(app(ImportLogger::class), app(ImportStatusService::class), $e);
    }

    private function recordFailure(ImportLogger $logger, ImportStatusService $statusService, Throwable $e): void
    {
        $run = $this->importRun();

        if ($run === null || $run->status === ImportRunStatus::Canceled || $run->status === ImportRunStatus::Failed) {
            return;
        }

        $logger->warning($run, 'Не удалось скачать изображение товара', [
            'product_id' => $this->productId,
            'url' => $this->url,
            'error' => $e->getMessage(),
        ]);

        $statusService->imageFailed($run);
    }

    private function importRun(): ?ImportRun
    {
        return $this->importRunId === null ? null : ImportRun::query()->find($this->importRunId);
    }
}
