<?php

namespace App\Services\Search;

use App\Jobs\PublishCatalogSearch;
use App\Models\ImportRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Durable outbox: its creation and the assortment publication share one transaction. */
final class CatalogSearchPublication
{
    public function record(ImportRun $run): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }
        DB::table('catalog_search_publications')->insertOrIgnore([
            'import_run_id' => $run->id, 'next_attempt_at' => now(),
        ]);
        DB::afterCommit(fn () => $this->dispatchPending());
    }

    /** Also called by the scheduler to recover lost dispatches or exhausted retries. */
    public function dispatchPending(): void
    {
        if (config('scout.driver') !== 'meilisearch' || ! Schema::hasTable('catalog_search_publications')) {
            return;
        }
        // Drain publications in order, avoiding concurrent full catalog scans.
        DB::transaction(function (): void {
            $publication = DB::table('catalog_search_publications')->whereNull('completed_at')
                ->orderBy('id')->lockForUpdate()->first();
            if ($publication === null || now()->lt($publication->next_attempt_at)) {
                return;
            }
            DB::table('catalog_search_publications')->where('id', $publication->id)
                ->update(['next_attempt_at' => now()->addMinutes(15)]);
            DB::afterCommit(function () use ($publication): void {
                try {
                    Bus::dispatch(new PublishCatalogSearch($publication->id));
                } catch (Throwable $e) {
                    // The durable request survives an unavailable queue or worker crash.
                    report($e);
                }
            });
        });
    }

    public function process(int $id, CatalogSearchIndexer $indexer): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }
        DB::transaction(function () use ($id, $indexer): void {
            $row = DB::table('catalog_search_publications')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->completed_at !== null) {
                return;
            }
            $classes = array_values(CatalogSearchDocument::MODELS);
            $class = $classes[$row->model_position];
            $models = $class::withTrashed()->with(CatalogSearchDocument::relations(new $class))
                ->where('id', '>', $row->last_id)->orderBy('id')
                ->limit(max(1, (int) config('scout.chunk.searchable', 100)))->get();
            if ($models->isNotEmpty()) {
                // Advance only after Meilisearch confirms success, not merely task acceptance.
                // Replaying an acknowledged batch after a DB/worker failure is idempotent.
                $indexer->refreshConfirmed($models);
                $changes = ['last_id' => $models->last()->id];
            } else {
                $position = $row->model_position + 1;
                $changes = ['model_position' => $position, 'last_id' => 0,
                    'completed_at' => $position === count($classes) ? now() : null];
            }
            DB::table('catalog_search_publications')->where('id', $id)
                ->update($changes + ['next_attempt_at' => now()]);
            DB::afterCommit(fn () => $this->dispatchPending());
        });
    }
}
