<?php

namespace App\Services\Media;

use App\Data\ProcessedImage;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaFileCleanupService
{
    /** @param array<string, mixed>|null $conversions */
    public function deleteAfterCommit(?string $path, ?array $conversions, string $disk = 'public'): void
    {
        $pathToDelete = $path;
        $conversionsToDelete = $conversions;
        $diskToUse = $disk;

        DB::afterCommit(function () use ($pathToDelete, $conversionsToDelete, $diskToUse): void {
            $this->deletePath($pathToDelete, $diskToUse);
            $this->deleteConversions($conversionsToDelete, $diskToUse);
        });
    }

    /** @param array<string, mixed>|null $oldConversions */
    public function scheduleReplacementCleanup(
        ProcessedImage $processed,
        ?string $sourcePath,
        ?string $oldPath,
        ?array $oldConversions,
        string $oldDisk = 'public',
    ): void {
        $processedPath = $processed->path;
        $processedConversions = $processed->conversions;
        $processedDisk = $processed->disk;
        $sourcePathToDelete = $sourcePath;
        $oldPathToDelete = $oldPath;
        $oldConversionsToDelete = $oldConversions;
        $oldDiskToUse = $oldDisk;

        DB::afterCommit(function () use (
            $processedPath,
            $sourcePathToDelete,
            $oldPathToDelete,
            $oldConversionsToDelete,
            $oldDiskToUse,
        ): void {
            if ($oldPathToDelete !== $processedPath) {
                $this->deletePath($oldPathToDelete, $oldDiskToUse);
                $this->deleteConversions($oldConversionsToDelete, $oldDiskToUse);
            }

            if ($sourcePathToDelete !== $processedPath && $sourcePathToDelete !== $oldPathToDelete) {
                $this->deletePath($sourcePathToDelete, 'public');
            }
        });

        $rollbackCleanup = function () use (
            $processedPath,
            $processedConversions,
            $processedDisk,
            $sourcePathToDelete,
            $oldPathToDelete,
        ): void {
            $this->deletePath($processedPath, $processedDisk);
            $this->deleteConversions($processedConversions, $processedDisk);

            if ($sourcePathToDelete !== $oldPathToDelete) {
                $this->deletePath($sourcePathToDelete, 'public');
            }
        };

        $transactions = app('db.transactions');

        if ($transactions instanceof DatabaseTransactionsManager) {
            $connectionName = DB::connection()->getName();
            $current = $transactions->getPendingTransactions()
                ->last(fn ($transaction): bool => $transaction->connection === $connectionName);
            ($current?->parent ?? $current)?->addCallbackForRollback($rollbackCleanup);
        } else {
            DB::afterRollBack($rollbackCleanup);
        }
    }

    public function deleteProcessedImage(ProcessedImage $image): void
    {
        $this->deletePath($image->path, $image->disk);
        $this->deleteConversions($image->conversions, $image->disk);
    }

    /** @param array<string, mixed>|null $conversions */
    public function deleteConversions(?array $conversions, string $defaultDisk = 'public'): void
    {
        foreach ($conversions ?? [] as $conversion) {
            if (! is_array($conversion)) {
                continue;
            }

            $path = $conversion['path'] ?? null;
            $disk = $conversion['disk'] ?? $defaultDisk;

            if (is_string($path) && $path !== '') {
                $this->deletePath($path, is_string($disk) && $disk !== '' ? $disk : $defaultDisk);
            }
        }
    }

    public function deletePath(?string $path, string $disk = 'public'): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $storage = Storage::disk($disk);

        if ($storage->exists($path)) {
            $storage->delete($path);
        }
    }
}
