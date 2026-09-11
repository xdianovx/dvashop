<?php

namespace App\Services\Search;

use Closure;

final class CatalogSearchSync
{
    private static int $suppressed = 0;

    public static function enabled(): bool
    {
        return self::$suppressed === 0 && config('scout.driver') === 'meilisearch';
    }

    public static function withoutSyncing(Closure $callback): mixed
    {
        self::$suppressed++;
        $run = $callback;
        foreach (CatalogSearchDocument::MODELS as $class) {
            $next = $run;
            $run = fn () => $class::withoutSyncingToSearch($next);
        }
        try {
            return $run();
        } finally {
            self::$suppressed--;
        }
    }
}
