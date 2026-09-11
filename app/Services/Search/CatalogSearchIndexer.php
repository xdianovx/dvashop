<?php

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CatalogSearchIndexer
{
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
