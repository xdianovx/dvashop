<?php

namespace App\Observers;

use App\Enums\ImportRunStatus;
use App\Jobs\RefreshCatalogSearchDependencies;
use App\Models\ImportRun;
use Illuminate\Support\Facades\DB;

final class CatalogSearchImportObserver
{
    public function updated(ImportRun $run): void
    {
        if (config('scout.driver') === 'meilisearch' && $run->type === 'catalog'
            && $run->wasChanged('status') && $run->status === ImportRunStatus::Done && $run->errors_count === 0) {
            DB::afterCommit(fn () => RefreshCatalogSearchDependencies::dispatch());
        }
    }
}
