<?php

namespace App\Console\Commands;

use App\Services\Search\CatalogSearchAliasFileService;
use Illuminate\Console\Command;
use Throwable;

class CatalogSearchAliasesExport extends Command
{
    protected $signature = 'catalog-search:aliases:export {file=storage/app/catalog-search/verified-aliases.json : Output JSON file}';

    protected $description = 'Export verified make/model search aliases to a deterministic UTF-8 JSON file.';

    public function handle(CatalogSearchAliasFileService $aliases): int
    {
        try {
            $path = $aliases->exportTo((string) $this->argument('file'));
            $this->info('Verified aliases exported: '.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
