<?php

namespace App\Models;

use App\Enums\ImportRunStatus;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\Import\ImportImageTasks;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'original_name',
        'stored_path',
        'configuration_path',
        'configuration_hash',
        'import_mode',
        'file_hash',
        'file_size',
        'mime_type',
        'total_rows',
        'processed_rows',
        'current_row',
        'chunk_size',
        'detail_columns',
        'prepared_rows',
        'initialized_at',
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
        'archive_skipped',
        'archive_skip_reason',
        'queued_images',
        'queued_vehicle_image_keys',
        'processed_images',
        'failed_images',
        'warnings_count',
        'errors_count',
        'last_error',
        'started_at',
        'finished_at',
        'heartbeat_at',
    ];

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            if ($this->isDirty('status') && $this->status?->isRowsRunning()) {
                app(CatalogPriceGuard::class)->assertWritable();
            }

            return parent::save($options);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ImportRunStatus::class,
            'file_size' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'current_row' => 'integer',
            'chunk_size' => 'integer',
            'configuration_review' => 'array',
            'configuration_confirmed_by' => 'integer',
            'configuration_confirmed_at' => 'datetime',
            'detail_columns' => 'array',
            'prepared_rows' => 'array',
            'initialized_at' => 'datetime',
            'created_makes' => 'integer',
            'updated_makes' => 'integer',
            'created_models' => 'integer',
            'updated_models' => 'integer',
            'created_generations' => 'integer',
            'updated_generations' => 'integer',
            'created_categories' => 'integer',
            'updated_categories' => 'integer',
            'created_products' => 'integer',
            'updated_products' => 'integer',
            'archived_products' => 'integer',
            'archive_skipped' => 'boolean',
            'queued_images' => 'integer',
            'queued_vehicle_image_keys' => 'array',
            'processed_images' => 'integer',
            'failed_images' => 'integer',
            'warnings_count' => 'integer',
            'errors_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function isSnapshotCatalogImport(): bool
    {
        return ($this->prepared_rows['snapshot_version'] ?? null) === 1;
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ImportLog::class);
    }

    public function progressPercent(): int
    {
        return $this->rowsProgressPercent();
    }

    public function rowsProgressPercent(): int
    {
        if ($this->total_rows <= 0) {
            return 0;
        }

        return min(100, (int) floor(($this->processed_rows / $this->total_rows) * 100));
    }

    public function imagesProgressPercent(): int
    {
        if ($this->queued_images <= 0) {
            return 0;
        }

        return min(100, (int) floor((($this->processed_images + $this->failed_images) / $this->queued_images) * 100));
    }

    public function hasPendingImages(): bool
    {
        return ($this->queued_images > 0 && ($this->processed_images + $this->failed_images) < $this->queued_images)
            || app(ImportImageTasks::class)->hasUnfinished($this);
    }

    public function imagesFinished(): bool
    {
        return ! $this->hasPendingImages();
    }

    public function isActive(): bool
    {
        return $this->status?->isActive() ?? false;
    }

    public function isTerminal(): bool
    {
        return $this->status?->isTerminal() ?? false;
    }
}
