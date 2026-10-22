<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Import\CatalogImportFormat;
use App\Services\Import\VehicleGenerationImageDispatchGuard;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** No writes to vehicles, products or variants: only enqueue images for unambiguous existing generations. */
final class CatalogImportImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $importRunId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('catalog-images-only-'.$this->importRunId))
            ->releaseAfter(10)->expireAfter(630)];
    }

    public function handle(
        SpreadsheetReader $reader,
        ImportStatusService $status,
        ImportLogger $logger,
        VehicleGenerationImageDispatchGuard $dispatchGuard,
    ): void {
        $run = ImportRun::findOrFail($this->importRunId);
        if ($run->import_mode !== 'images' || ! $run->status?->isRowsRunning()) {
            return;
        }
        try {
            $path = Storage::disk('local')->path($run->stored_path);
            if (! is_file($path) || ! hash_equals((string) $run->file_hash, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Исходный Excel для фотографий изменился или отсутствует.');
            }
            $queued = 0;
            $processed = 0;
            $missing = 0;
            foreach ($reader->catalogImageRows($path) as $entry) {
                if (! $run->fresh()->status?->isRowsRunning()) {
                    return;
                }
                if ($entry['row'] === null) {
                    $logger->warning($run, 'Пропущена строка изображения: '.$entry['reason'], ['row' => $entry['number']]);

                    continue;
                }
                $row = $entry['row'];
                $processed++;
                $urls = $row['_photo_urls'] ?? [];
                if ($urls === []) {
                    continue;
                }
                $make = VehicleMake::where('norm_key', CatalogText::normKey($row[1], 'make', 100))->first();
                $model = $make ? VehicleModel::where('vehicle_make_id', $make->id)
                    ->where('norm_key', CatalogText::normKey($row[2], 'model', 100))->first() : null;
                if (! $model) {
                    $missing++;

                    continue;
                }
                $generationKey = CatalogText::normKey(
                    trim($row[3].' '.$row[4].' '.CatalogImportFormat::body($row[5])), 'generation', 120,
                );
                $generations = VehicleGeneration::where('vehicle_model_id', $model->id)
                    ->where('norm_key', $generationKey)->limit(2)->get();
                if ($generations->count() !== 1) {
                    $missing++;
                    $logger->warning($run, 'Для изображения не найдено однозначное поколение автомобиля', [
                        'row' => $entry['number'], 'generation_key' => $generationKey,
                    ]);

                    continue;
                }
                $generation = $generations->first();
                // Keep manually replaced images. Never overwrite a valid one after failure.
                if ($generation->image && Storage::disk('public')->exists($generation->image)
                    && ($generation->image_source_url === null || in_array($generation->image_source_url, $urls, true))) {
                    continue;
                }
                if (! $dispatchGuard->queue($run, $generation, $urls[0], array_slice($urls, 1, 2))) {
                    continue;
                }
                $queued++;
            }
            $run->refresh();
            $run->forceFill(['total_rows' => $processed, 'processed_rows' => $processed,
                'current_row' => $processed, 'heartbeat_at' => now()])->save();
            $logger->info($run, 'Обновление фотографий: товарный каталог и наличие не изменялись', [
                'rows' => $processed, 'images_queued' => $queued, 'not_matched' => $missing,
            ]);
            $status->markRowsDone($run);
        } catch (Throwable $e) {
            $status->fail($run->fresh(), $e->getMessage());
        }
    }
}
