<?php

namespace App\Services\Media;

use App\Data\ProcessedImage;
use Illuminate\Support\Facades\Storage;

class MediaFileCleanupService
{
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
