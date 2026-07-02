<?php

namespace App\Services;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImportStatusService
{
    public function __construct(private readonly ImportLogger $logger) {}

    public function createFromUpload(UploadedFile $file, string $type = 'catalog'): ImportRun
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'csv');
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
            originalName: $file->getClientOriginalName(),
            storedPath: $storedPath,
            mimeType: $file->getClientMimeType(),
            fileSize: $file->getSize(),
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
            'file_size' => $fileSize ?? filesize($absolutePath) ?: null,
            'mime_type' => $mimeType,
            'chunk_size' => $chunkSize,
        ]);

        $this->logger->info($run, 'Файл импорта загружен', [
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'file_size' => $run->file_size,
        ]);

        return $run;
    }

    public function start(ImportRun $run): ImportRun
    {
        $run->forceFill([
            'status' => ImportRunStatus::Running,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'last_error' => null,
            'heartbeat_at' => now(),
        ])->save();

        return $run->refresh();
    }

    public function pause(ImportRun $run): ImportRun
    {
        if ($run->status === ImportRunStatus::Running) {
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
            $this->start($run);
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

    public function markDone(ImportRun $run): ImportRun
    {
        $run->forceFill([
            'status' => ImportRunStatus::Done,
            'processed_rows' => $run->total_rows,
            'current_row' => $run->total_rows,
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
}
