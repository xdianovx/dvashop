<?php

namespace App\Console\Commands;

use App\Services\Search\CatalogSearchDocument;
use App\Services\Search\CatalogSearchIndexer;
use Illuminate\Console\Command;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use RuntimeException;

class CatalogSearchRebuild extends Command
{
    protected $signature = 'catalog-search:rebuild';

    private \DateTimeImmutable $startedAt;

    protected $description = 'Sync Scout settings and refresh all catalog indexes in bounded chunks without flushing.';

    public function handle(CatalogSearchIndexer $indexer, Client $client): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->error('SCOUT_DRIVER must be meilisearch. Storefront rollout is controlled separately.');

            return self::FAILURE;
        }
        $this->startedAt = new \DateTimeImmutable;
        $client->health();
        $this->call('scout:sync-index-settings', ['--driver' => 'meilisearch']);
        $this->waitForTasks($client);
        foreach (CatalogSearchDocument::MODELS as $class) {
            $count = $indexer->refresh($class::query());
            $this->waitForTasks($client);
            $this->info((new $class)->searchableAs().': '.$count.' searchable records; '.$client->index((new $class)->searchableAs())->stats()['numberOfDocuments'].' indexed documents.');
        }

        return self::SUCCESS;
    }

    private function waitForTasks(Client $client): void
    {
        foreach (CatalogSearchDocument::MODELS as $class) {
            $options = (new TasksQuery)->setIndexUids([(new $class)->searchableAs()])->setAfterEnqueuedAt($this->startedAt)->setLimit(100);
            do {
                $page = $client->getTasks($options);
                foreach ($page->getResults() as $pending) {
                    $task = $client->waitForTask($pending['uid'], 300000, 100);
                    if ($task['status'] !== 'succeeded') {
                        throw new RuntimeException('Catalog search indexing task failed: '.($task['error']['code'] ?? $task['status']));
                    }
                }
                $next = $page->getNext();
                $options->setFrom($next);
            } while ($next !== 0);
        }
    }
}
