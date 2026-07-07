<?php

namespace App\Services;

use App\Enums\ImportLogLevel;
use App\Models\ImportLog;
use App\Models\ImportRun;
use Illuminate\Support\Collection;

class ImportLogger
{
    public function __construct(private readonly ImportRunStats $stats) {}

    public function info(ImportRun $run, string $message, array $context = []): ImportLog
    {
        return $this->write($run, ImportLogLevel::Info, $message, $context);
    }

    public function warning(ImportRun $run, string $message, array $context = []): ImportLog
    {
        return $this->write($run, ImportLogLevel::Warning, $message, $context);
    }

    public function error(ImportRun $run, string $message, array $context = []): ImportLog
    {
        return $this->write($run, ImportLogLevel::Error, $message, $context);
    }

    public function write(ImportRun $run, ImportLogLevel $level, string $message, array $context = []): ImportLog
    {
        $log = ImportLog::query()->create([
            'import_run_id' => $run->getKey(),
            'level' => $level,
            'message' => $message,
            'context' => $context === [] ? null : $context,
        ]);

        if ($level === ImportLogLevel::Warning) {
            $this->stats->increment($run, 'warnings_count');
        }

        if ($level === ImportLogLevel::Error) {
            $this->stats->increment($run, 'errors_count');
        }

        return $log;
    }

    /** @return Collection<int, ImportLog> */
    public function latest(ImportRun $run, int $limit = 50): Collection
    {
        return ImportLog::query()
            ->where('import_run_id', $run->getKey())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
