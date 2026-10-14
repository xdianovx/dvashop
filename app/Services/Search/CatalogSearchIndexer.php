<?php

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Meilisearch\Client;
use RuntimeException;

final class CatalogSearchIndexer
{
    /** Same Scout document contract, with explicit asynchronous task acknowledgement. */
    public function refreshConfirmed(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }
        $client = app(Client::class);
        $first = $models->first();
        $index = $client->index($first->indexableAs());
        [$deleted, $live] = $models->partition(fn ($model) => $model->trashed());
        $tasks = [];
        if ($deleted->isNotEmpty()) {
            $tasks[] = $index->deleteDocuments($deleted->map->getScoutKey()->all());
        }
        if ($live->isNotEmpty()) {
            $documents = $live->map(fn ($model) => array_merge($model->toSearchableArray(),
                $model->scoutMetadata(), [$model->getScoutKeyName() => $model->getScoutKey()]))->values()->all();
            $tasks[] = $index->addDocuments($documents, $first->getScoutKeyName());
        }
        foreach ($tasks as $task) {
            $result = $client->waitForTask($task['taskUid'], 30000, 50);
            if ($result['status'] !== 'succeeded') {
                throw new RuntimeException('Meilisearch task '.$task['taskUid'].' failed: '.json_encode($result['error'] ?? []));
            }
        }
    }

    public function refresh(Builder $query): int
    {
        $count = 0;
        $model = $query->getModel();
        $query->withTrashed()->with(CatalogSearchDocument::relations($model))
            ->chunkById((int) config('scout.chunk.searchable', 100), function (Collection $models) use (&$count): void {
                [$deleted, $live] = $models->partition(fn ($model) => $model->trashed());
                $engine = $models->first()->searchableUsing();
                if ($deleted->isNotEmpty()) {
                    $engine->delete($deleted);
                }
                if ($live->isNotEmpty()) {
                    $engine->update($live);
                    $count += $live->count();
                }
            });

        return $count;
    }
}
