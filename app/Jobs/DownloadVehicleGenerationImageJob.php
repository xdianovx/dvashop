<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use App\Services\Import\ImportImageDownloader;
use App\Services\ImportLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DownloadVehicleGenerationImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $vehicleGenerationId,
        public string $url,
        public ?int $importRunId = null,
    ) {}

    public function handle(ImportImageDownloader $downloader, ImportLogger $logger): void
    {
        $generation = VehicleGeneration::query()->findOrFail($this->vehicleGenerationId);

        try {
            $downloader->downloadVehicleGenerationImage($generation, $this->url);
        } catch (Throwable $e) {
            if ($this->importRunId !== null && ($run = ImportRun::query()->find($this->importRunId))) {
                $logger->warning($run, 'Не удалось скачать изображение поколения авто', [
                    'vehicle_generation_id' => $this->vehicleGenerationId,
                    'url' => $this->url,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
