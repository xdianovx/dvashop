<?php

namespace Database\Factories;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportRun> */
class ImportRunFactory extends Factory
{
    protected $model = ImportRun::class;

    public function definition(): array
    {
        return [
            'type' => 'catalog',
            'status' => ImportRunStatus::Ready,
            'original_name' => 'catalog.csv',
            'stored_path' => 'imports/catalog/catalog.csv',
            'file_hash' => hash('sha256', fake()->uuid()),
            'file_size' => 1024,
            'mime_type' => 'text/csv',
            'total_rows' => 0,
            'processed_rows' => 0,
            'current_row' => 0,
            'chunk_size' => 300,
            'created_makes' => 0,
            'updated_makes' => 0,
            'created_models' => 0,
            'updated_models' => 0,
            'created_generations' => 0,
            'updated_generations' => 0,
            'created_categories' => 0,
            'updated_categories' => 0,
            'created_products' => 0,
            'updated_products' => 0,
            'archived_products' => 0,
            'archive_skipped' => false,
            'archive_skip_reason' => null,
            'queued_images' => 0,
            'processed_images' => 0,
            'failed_images' => 0,
            'warnings_count' => 0,
            'errors_count' => 0,
        ];
    }
}
