<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Import\ImportImageDownloader;
use App\Services\ImportLogger;
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

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $productId,
        public string $url,
        public ?int $importRunId = null,
    ) {}

    public function handle(ImportImageDownloader $downloader, ImportLogger $logger): void
    {
        $product = Product::query()->findOrFail($this->productId);

        try {
            $downloader->download($product, $this->url);
        } catch (Throwable $e) {
            if ($this->importRunId !== null && ($run = \App\Models\ImportRun::query()->find($this->importRunId))) {
                $logger->warning($run, 'Не удалось скачать изображение товара', [
                    'product_id' => $this->productId,
                    'url' => $this->url,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
