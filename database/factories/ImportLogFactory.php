<?php

namespace Database\Factories;

use App\Enums\ImportLogLevel;
use App\Models\ImportLog;
use App\Models\ImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportLog> */
class ImportLogFactory extends Factory
{
    protected $model = ImportLog::class;

    public function definition(): array
    {
        return [
            'import_run_id' => ImportRun::factory(),
            'level' => ImportLogLevel::Info,
            'message' => fake()->sentence(),
            'context' => null,
        ];
    }
}
