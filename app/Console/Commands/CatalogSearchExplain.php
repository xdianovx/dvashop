<?php

namespace App\Console\Commands;

use App\Services\Search\MeilisearchCatalogSearchProvider;
use Illuminate\Console\Command;

class CatalogSearchExplain extends Command
{
    protected $signature = 'catalog-search:explain {query : Search query to explain}';

    protected $description = 'Explain dynamic catalog search normalization, channels, candidates, and confidence decisions.';

    public function handle(MeilisearchCatalogSearchProvider $provider): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->error('SCOUT_DRIVER must be meilisearch for catalog-search:explain.');

            return self::FAILURE;
        }

        $this->line(json_encode(
            $provider->explain((string) $this->argument('query')),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
