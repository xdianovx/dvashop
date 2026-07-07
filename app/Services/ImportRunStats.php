<?php

namespace App\Services;

use App\Models\ImportRun;

class ImportRunStats
{
    public const COUNTERS = [
        'created_makes',
        'updated_makes',
        'created_models',
        'updated_models',
        'created_generations',
        'updated_generations',
        'created_categories',
        'updated_categories',
        'created_products',
        'updated_products',
        'archived_products',
        'queued_images',
        'processed_images',
        'failed_images',
        'warnings_count',
        'errors_count',
    ];

    public function increment(ImportRun $run, string $counter, int $by = 1): void
    {
        if ($by <= 0 || ! in_array($counter, self::COUNTERS, true)) {
            return;
        }

        ImportRun::query()
            ->whereKey($run->getKey())
            ->increment($counter, $by, ['heartbeat_at' => now()]);
    }

    /** @param array<string, int> $counters */
    public function incrementMany(ImportRun $run, array $counters): void
    {
        foreach ($counters as $counter => $by) {
            $this->increment($run, $counter, (int) $by);
        }
    }
}
