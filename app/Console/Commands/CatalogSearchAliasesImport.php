<?php

namespace App\Console\Commands;

use App\Services\Search\CatalogSearchAliasFileService;
use Illuminate\Console\Command;
use Throwable;

class CatalogSearchAliasesImport extends Command
{
    protected $signature = 'catalog-search:aliases:import {file : Input JSON file} {--dry-run : Validate and report changes without writing or dispatching reindex jobs}';

    protected $description = 'Idempotently import verified make/model search aliases without changing canonical vehicle identity.';

    public function handle(CatalogSearchAliasFileService $aliases): int
    {
        try {
            $summary = $aliases->importFrom((string) $this->argument('file'), (bool) $this->option('dry-run'));
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $summary['errors'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
