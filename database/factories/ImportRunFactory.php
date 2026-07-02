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
        ];
    }
}
