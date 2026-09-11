<?php

namespace App\Models\Concerns;

use App\Services\Search\SearchText;

trait HasSearchAliases
{
    public static function bootHasSearchAliases(): void
    {
        static::saving(function (self $model): void {
            if ($model->isDirty('search_aliases')) {
                $model->search_aliases = SearchText::aliases($model->search_aliases);
            }
        });
    }
}
