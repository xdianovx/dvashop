<?php

use App\Jobs\RefreshCatalogSearchDependencies;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\CatalogSearchAliasFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('verified aliases export uses stable canonical identity and utf8 arrays only', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Peugeot', 'search_aliases' => ['Пежо']]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Partner', 'search_aliases' => ['Партнер']]);
    $file = tempnam(sys_get_temp_dir(), 'catalog-alias-export-');

    try {
        expect(Artisan::call('catalog-search:aliases:export', ['file' => $file]))->toBe(0);
        $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $makeRow = collect($payload['makes'])->firstWhere('id', $make->id);
        $modelRow = collect($payload['models'])->firstWhere('id', $model->id);

        expect($payload['version'])->toBe(1)
            ->and($makeRow)->toMatchArray([
                'id' => $make->id,
                'norm_key' => $make->norm_key,
                'title' => 'Peugeot',
                'aliases' => ['Пежо'],
            ])
            ->and($modelRow)->toMatchArray([
                'id' => $model->id,
                'vehicle_make_id' => $make->id,
                'make_norm_key' => $make->norm_key,
                'make' => 'Peugeot',
                'norm_key' => $model->norm_key,
                'title' => 'Partner',
                'aliases' => ['Партнер'],
            ])
            ->and(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain('phonetic', 'strict_transliteration');
    } finally {
        @unlink($file);
    }
});

test('verified alias import is dry run safe idempotent and only mutates search aliases', function (): void {
    config(['scout.driver' => 'meilisearch']);
    Queue::fake();
    $make = VehicleMake::factory()->create(['title' => 'Chevrolet', 'search_aliases' => null]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Tahoe', 'search_aliases' => null]);
    $makeIdentity = $make->only(['title', 'slug', 'norm_key']);
    $modelIdentity = $model->only(['vehicle_make_id', 'title', 'slug', 'norm_key']);
    $payload = app(CatalogSearchAliasFileService::class)->exportData();
    foreach ($payload['makes'] as &$row) {
        if ($row['id'] === $make->id) {
            $row['aliases'] = ['  Шевроле  ', 'шевроле', 'Chevy RU'];
        }
    }
    unset($row);
    foreach ($payload['models'] as &$row) {
        if ($row['id'] === $model->id) {
            $row['aliases'] = [' Тахо ', 'ТАХО'];
        }
    }
    unset($row);
    $file = tempnam(sys_get_temp_dir(), 'catalog-alias-import-');
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    Queue::fake();

    try {
        $dryRun = app(CatalogSearchAliasFileService::class)->importFrom($file, true);
        expect($dryRun)->toMatchArray(['dry_run' => true, 'created' => 2, 'updated' => 0, 'errors' => 0, 'reindex_jobs' => 0])
            ->and($make->fresh()->search_aliases)->toBe([])
            ->and($model->fresh()->search_aliases)->toBe([]);
        Queue::assertNothingPushed();

        $applied = app(CatalogSearchAliasFileService::class)->importFrom($file);
        expect($applied)->toMatchArray(['dry_run' => false, 'created' => 2, 'updated' => 0, 'errors' => 0, 'reindex_jobs' => 2])
            ->and($make->fresh()->search_aliases)->toBe(['Шевроле', 'Chevy RU'])
            ->and($model->fresh()->search_aliases)->toBe(['Тахо'])
            ->and($make->fresh()->only(array_keys($makeIdentity)))->toBe($makeIdentity)
            ->and($model->fresh()->only(array_keys($modelIdentity)))->toBe($modelIdentity);
        Queue::assertPushed(RefreshCatalogSearchDependencies::class, 2);
        Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn (RefreshCatalogSearchDependencies $job): bool => $job->source === VehicleMake::class && $job->ids === [$make->id]);
        Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn (RefreshCatalogSearchDependencies $job): bool => $job->source === VehicleModel::class && $job->ids === [$model->id]);

        Queue::fake();
        $second = app(CatalogSearchAliasFileService::class)->importFrom($file);
        expect($second)->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'errors' => 0, 'reindex_jobs' => 0]);
        Queue::assertNothingPushed();
    } finally {
        @unlink($file);
    }
});

test('verified alias import rejects identity mismatches and invalid aliases without touching canonical fields', function (): void {
    config(['scout.driver' => 'meilisearch']);
    Queue::fake();
    $make = VehicleMake::factory()->create(['title' => 'Renault', 'search_aliases' => null]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Duster', 'search_aliases' => null]);
    $identity = $model->only(['vehicle_make_id', 'title', 'slug', 'norm_key']);
    $payload = app(CatalogSearchAliasFileService::class)->exportData();
    foreach ($payload['models'] as &$row) {
        if ($row['id'] === $model->id) {
            $row['norm_key'] = 'wrong-identity';
            $row['aliases'] = ['<b>bad</b>'];
        }
    }
    unset($row);
    $file = tempnam(sys_get_temp_dir(), 'catalog-alias-invalid-');
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    Queue::fake();

    try {
        $summary = app(CatalogSearchAliasFileService::class)->importFrom($file);
        expect($summary['errors'])->toBe(1)
            ->and($model->fresh()->search_aliases)->toBe([])
            ->and($model->fresh()->only(array_keys($identity)))->toBe($identity);
        Queue::assertNothingPushed();
    } finally {
        @unlink($file);
    }
});

test('verified alias import is atomic when any row fails phase a validation', function (): void {
    config(['scout.driver' => 'meilisearch']);
    Queue::fake();

    $makeA = VehicleMake::factory()->create(['title' => 'Atomic Make A', 'search_aliases' => null]);
    $makeB = VehicleMake::factory()->create(['title' => 'Atomic Make B', 'search_aliases' => null]);
    $modelB = VehicleModel::factory()->forMake($makeB)->create(['title' => 'Atomic Model B', 'search_aliases' => null]);
    $modelC = VehicleModel::factory()->forMake($makeB)->create(['title' => 'Atomic Model C', 'search_aliases' => null]);

    $payload = app(CatalogSearchAliasFileService::class)->exportData();
    foreach ($payload['makes'] as &$row) {
        if ($row['id'] === $makeA->id) {
            $row['aliases'] = [' Алиас Make A '];
        }
    }
    unset($row);
    foreach ($payload['models'] as &$row) {
        if ($row['id'] === $modelB->id) {
            $row['aliases'] = [' Алиас Model B '];
        }
        if ($row['id'] === $modelC->id) {
            $row['aliases'] = [' Алиас Model C '];
            $row['norm_key'] = 'invalid-identity';
        }
    }
    unset($row);

    $file = tempnam(sys_get_temp_dir(), 'catalog-alias-atomic-');
    try {
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        Queue::fake();
        $failed = app(CatalogSearchAliasFileService::class)->importFrom($file);

        expect($failed['errors'])->toBe(1)
            ->and($failed['reindex_jobs'])->toBe(0)
            ->and($makeA->fresh()->search_aliases)->toBe([])
            ->and($modelB->fresh()->search_aliases)->toBe([])
            ->and($modelC->fresh()->search_aliases)->toBe([]);
        Queue::assertNothingPushed();

        foreach ($payload['models'] as &$row) {
            if ($row['id'] === $modelC->id) {
                $row['norm_key'] = $modelC->norm_key;
            }
        }
        unset($row);
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $applied = app(CatalogSearchAliasFileService::class)->importFrom($file);
        expect($applied)->toMatchArray(['errors' => 0, 'created' => 3, 'updated' => 0, 'reindex_jobs' => 2])
            ->and($makeA->fresh()->search_aliases)->toBe(['Алиас Make A'])
            ->and($modelB->fresh()->search_aliases)->toBe(['Алиас Model B'])
            ->and($modelC->fresh()->search_aliases)->toBe(['Алиас Model C']);
        Queue::assertPushed(RefreshCatalogSearchDependencies::class, 2);
        Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn (RefreshCatalogSearchDependencies $job): bool => $job->source === VehicleMake::class && $job->ids === [$makeA->id]);
        Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn (RefreshCatalogSearchDependencies $job): bool => $job->source === VehicleModel::class && $job->ids === [$modelB->id, $modelC->id]);
    } finally {
        @unlink($file);
    }
});
