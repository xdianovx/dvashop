<?php

namespace App\Models\Concerns;

use App\Services\Search\CatalogSearchDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

trait CatalogSearchable
{
    use Searchable;

    public function searchableAs(): string
    {
        return config('scout.prefix').'catalog_'.$this->getTable();
    }

    public function toSearchableArray(): array
    {
        return CatalogSearchDocument::make($this);
    }

    public function makeSearchableUsing(Collection $models): Collection
    {
        $models->load(CatalogSearchDocument::relations($this));

        return $models;
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(CatalogSearchDocument::relations($this));
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return config('scout.driver') === 'meilisearch';
    }

    public function wasSearchableBeforeDelete(): bool
    {
        return config('scout.driver') === 'meilisearch';
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }
}
