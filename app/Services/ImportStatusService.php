<?php

namespace App\Services;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportStatusService
{
    public function __construct(
        private readonly ImportLogger $logger,
        private readonly ImportRunStats $stats,
    ) {}

    public function createFromUpload(UploadedFile $file, string $type = 'catalog', int $chunkSize = 300): ImportRun
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'csv');
        $originalName = $file->getClientOriginalName();
        $mimeType = $this->safeUploadedMimeType($file);

        $storedPath = $file->storeAs(
            "imports/{$type}",
            Str::uuid()->toString().'.'.$extension,
            'local'
        );

        if (! is_string($storedPath)) {
            throw new RuntimeException('Не удалось сохранить файл импорта.');
        }

        return $this->createFromStoredPath(
            type: $type,
            originalName: $originalName,
            storedPath: $storedPath,
            mimeType: $mimeType ?? $this->storedMimeType($storedPath),
            fileSize: $this->storedFileSize($storedPath),
            chunkSize: $chunkSize,
        );
    }

    public function createFromStoredPath(
        string $type,
        string $originalName,
        string $storedPath,
        ?string $mimeType = null,
        ?int $fileSize = null,
        int $chunkSize = 300,
    ): ImportRun {
        $absolutePath = Storage::disk('local')->path($storedPath);

        $run = ImportRun::query()->create([
            'type' => $type,
            'status' => ImportRunStatus::Ready,
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'file_hash' => hash_file('sha256', $absolutePath),
            'file_size' => $fileSize ?? $this->storedFileSize($storedPath),
            'mime_type' => $mimeType ?? $this->storedMimeType($storedPath),
            'chunk_size' => $chunkSize,
        ]);

        $this->logger->info($run, 'Файл импорта загружен', [
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'file_size' => $run->file_size,
            'type' => $run->type,
            'chunk_size' => $run->chunk_size,
        ]);

        return $run;
    }

    private function safeUploadedMimeType(UploadedFile $file): ?string
    {
        try {
            $mimeType = $file->getClientMimeType();
        } catch (Throwable) {
            return null;
        }

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
    }

    private function storedFileSize(string $storedPath): ?int
    {
        try {
            $size = Storage::disk('local')->size($storedPath);

            if (is_int($size)) {
                return $size;
            }
        } catch (Throwable) {
            // Fall back to the absolute path below. Livewire temporary files can lose
            // metadata after they are moved, but the stored import file is stable.
        }

        $absolutePath = Storage::disk('local')->path($storedPath);
        $size = is_file($absolutePath) ? filesize($absolutePath) : false;

        return is_int($size) ? $size : null;
    }

    private function storedMimeType(string $storedPath): ?string
    {
        try {
            $mimeType = Storage::disk('local')->mimeType($storedPath);
        } catch (Throwable) {
            return null;
        }

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
    }

    public function start(ImportRun $run): ImportRun
    {
        $run->forceFill([
            'status' => ImportRunStatus::RunningRows,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'last_error' => null,
            'heartbeat_at' => now(),
        ])->save();

        return $run->refresh();
    }

    public function pause(ImportRun $run): ImportRun
    {
        if ($run->status?->isRowsRunning()) {
            $run->forceFill([
                'status' => ImportRunStatus::Paused,
                'heartbeat_at' => now(),
            ])->save();

            $this->logger->info($run, 'Импорт поставлен на паузу');
        }

        return $run->refresh();
    }

    public function resume(ImportRun $run): ImportRun
    {
        if ($run->status === ImportRunStatus::Paused) {
            if ($run->processed_rows >= $run->total_rows && $run->hasPendingImages()) {
                $run->forceFill([
                    'status' => ImportRunStatus::ProcessingImages,
                    'heartbeat_at' => now(),
                ])->save();
            } else {
                $this->start($run);
            }

            $this->logger->info($run, 'Импорт продолжен');
        }

        return $run->refresh();
    }

    public function cancel(ImportRun $run): ImportRun
    {
        if (! $run->isTerminal()) {
            $run->forceFill([
                'status' => ImportRunStatus::Canceled,
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ])->save();

            $this->logger->warning($run, 'Импорт отменён');
        }

        return $run->refresh();
    }

    public function markRowsDone(ImportRun $run): ImportRun
    {
        $run->refresh();
        $run->forceFill([
            'processed_rows' => $run->total_rows,
            'current_row' => $run->total_rows,
            'status' => $run->hasPendingImages() ? ImportRunStatus::ProcessingImages : ImportRunStatus::Done,
            'finished_at' => $run->hasPendingImages() ? null : now(),
            'heartbeat_at' => now(),
        ])->save();

        return $run->refresh();
    }

    public function markDone(ImportRun $run): ImportRun
    {
        $run->forceFill([
            'status' => ImportRunStatus::Done,
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ])->save();

        return $run->refresh();
    }

    public function fail(ImportRun $run, string $error): ImportRun
    {
        $run->forceFill([
            'status' => ImportRunStatus::Failed,
            'last_error' => $error,
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ])->save();

        $this->logger->error($run, 'Импорт завершился ошибкой', ['error' => $error]);

        return $run->refresh();
    }

    public function heartbeat(ImportRun $run): ImportRun
    {
        $run->forceFill(['heartbeat_at' => now()])->save();

        return $run->refresh();
    }

    public function imageQueued(ImportRun $run, int $count = 1): void
    {
        $this->stats->increment($run, 'queued_images', $count);
    }

    public function imageProcessed(ImportRun $run): ImportRun
    {
        $this->stats->increment($run, 'processed_images');

        return $this->finishImagesIfComplete($run);
    }

    public function imageFailed(ImportRun $run): ImportRun
    {
        $this->stats->increment($run, 'failed_images');

        return $this->finishImagesIfComplete($run);
    }

    public function finishImagesIfComplete(ImportRun $run): ImportRun
    {
        $run->refresh();

        if ($run->status === ImportRunStatus::ProcessingImages && $run->imagesFinished()) {
            $this->logger->info($run, 'Обработка изображений завершена', [
                'processed_images' => $run->processed_images,
                'failed_images' => $run->failed_images,
                'queued_images' => $run->queued_images,
            ]);

            return $this->markDone($run);
        }

        $run->forceFill(['heartbeat_at' => now()])->save();

        return $run->refresh();
    }
}
