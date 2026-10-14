<?php

namespace App\Jobs;

use App\Services\Search\CatalogSearchIndexer;
use App\Services\Search\CatalogSearchPublication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class PublishCatalogSearch implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $publicationId)
    {
        $this->onConnection(config('scout.queue.connection') ?: 'catalog-search');
        $this->onQueue('catalog-search');
        $this->afterCommit();
    }

    public function handle(CatalogSearchPublication $publication, CatalogSearchIndexer $indexer): void
    {
        $publication->process($this->publicationId, $indexer);
    }
}
