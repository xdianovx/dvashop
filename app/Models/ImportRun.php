<?php

namespace App\Models;

use App\Enums\ImportRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'original_name',
        'stored_path',
        'file_hash',
        'file_size',
        'mime_type',
        'total_rows',
        'processed_rows',
        'current_row',
        'chunk_size',
        'detail_columns',
        'last_error',
        'started_at',
        'finished_at',
        'heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportRunStatus::class,
            'file_size' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'current_row' => 'integer',
            'chunk_size' => 'integer',
            'detail_columns' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ImportLog::class);
    }

    public function progressPercent(): int
    {
        if ($this->total_rows <= 0) {
            return 0;
        }

        return min(100, (int) floor(($this->processed_rows / $this->total_rows) * 100));
    }

    public function isTerminal(): bool
    {
        return $this->status?->isTerminal() ?? false;
    }
}
