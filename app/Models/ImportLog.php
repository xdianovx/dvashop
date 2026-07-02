<?php

namespace App\Models;

use App\Enums\ImportLogLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_run_id',
        'level',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'level' => ImportLogLevel::class,
            'context' => 'array',
        ];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
