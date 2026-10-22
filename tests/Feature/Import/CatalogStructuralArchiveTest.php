<?php

use App\Enums\ImportRunStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogConfigurationInstallation;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Import\CatalogImportProductPlan;
use App\Services\Import\CatalogImportSnapshot;
use App\Services\Import\ImportProductFactory;
use App\Services\SpreadsheetReader;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (getenv('STRUCTURAL_MYSQL_TEST') === '1') {
        expect(DB::getDriverName())->toBe('mysql')
            ->and(DB::connection()->getDatabaseName())->toBe('dvashop_guard_test')
            ->and(DB::connection()->getConfig('host'))->toBe('dvashop_safe_partial_mysql_fix');
    }
    Storage::fake('local');
    Queue::fake();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
});

function structuralFixture(): array
{
    Storage::disk('local')->put('matrix.csv', "Название,Профиль,Положение,Материал,Толщина металла,Цена\nСтандартный порог,Стандартный,Левый,ХКС,1 мм,1000\nУвеличенный порог,Увеличенный,Левый,ХКС,1 мм,900\nАрка внутренняя,-,Левый,ХКС,1 мм,800\nБарабан,-,Левый,ХКС,1 мм,700\n");
    app(CatalogConfigurations::class)->install(Storage::disk('local')->path('matrix.csv'));
    $make = VehicleMake::factory()->create(['title' => 'Example', 'norm_key' => 'example']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Car', 'norm_key' => 'car']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => '1',
        'norm_key' => '1-2000-2005-sedan-4-dv', 'years_label' => '2000-2005', 'body' => 'Седан 4 дв.', 'platform' => null, 'restyling' => null]);
    $run = ImportRun::factory()->create(['type' => 'catalog', 'import_mode' => 'full',
        'original_name' => 'structural.xlsx', 'stored_path' => 'structural.xlsx', 'status' => ImportRunStatus::Done]);
    $paths = ['porog', 'arka/vnutrenniaia', 'porog/uvelichennyi', 'arka/vnutrenniaia-zadniaia', 'arka/vnutrenniaia-universalnaia', 'arka/karman-zadniaia'];
    $products = [];
    foreach ($paths as $path) {
        $p = Product::factory()->create(['part_type_id' => PartType::where('full_slug', $path)->value('id'),
            'import_source' => 'catalog', 'import_key' => 'catalog:example:car:1-2000-2005-sedan-4-dv:'.str_replace('/', ':', $path),
            'status' => $path === 'arka/vnutrenniaia-universalnaia' ? 'draft' : 'active',
            'publication_override' => $path === 'arka/vnutrenniaia-universalnaia' ? 'draft' : null,
            'last_import_run_id' => $run->id, 'import_present' => true, 'description' => 'Preserved history']);
        DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $generation->id, 'is_primary' => true]);
        $products[$path] = $p->fresh();
    }
    $variant = ProductVariant::factory()->forProduct($products['porog'])->make(['stock_quantity' => 3]);
    $variant = ProductVariant::findOrFail(DB::table('product_variants')->insertGetId($variant->getAttributes()));
    $parents = [$products['porog']->id, $products['arka/vnutrenniaia']->id];
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id,
        array_fill_keys([...$parents, $products['arka/karman-zadniaia']->id], 'outside_confirmed_assortment'), 2, str_repeat('a', 64)));
    $xlsx = Storage::disk('local')->path('structural.xlsx');
    $writer = new Writer;
    $writer->openToFile($xlsx);
    $writer->addRow(Row::fromValues(['', '', '', '', '', '', 'Порог', 'Порог', 'Арка', 'Арка', '']));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Стандартный', 'Увеличенный', 'Внутренняя задняя', 'Внутренняя передняя', 'Барабан']));
    $writer->addRow(Row::fromValues(['', 'Example', 'Car', '1', '2000-2005', 'Седан 4 двери', '', 1, 1, '', 1]));
    $writer->close();
    $sha = hash_file('sha256', $xlsx);
    $run->forceFill(['prepared_rows' => ['full_scope' => ['excel_sha256' => $sha,
        'preserve' => array_fill_keys($parents, 'structural_type_requires_mapping')]]])->save();
    $row = iterator_to_array(app(SpreadsheetReader::class)->catalogRows($xlsx))[0]['row'];
    $source = ['3' => ['vehicle' => array_slice($row, 1, 5), 'platform' => '', 'restyling' => '']];
    $csv = Storage::disk('local')->path('approved.csv');
    $stream = fopen($csv, 'wb');
    foreach (['porog' => ['porog/uvelichennyi' => 'H3'], 'arka/vnutrenniaia' => ['arka/vnutrenniaia-zadniaia' => 'I3', 'arka/vnutrenniaia-universalnaia' => 'K3']] as $path => $children) {
        $p = (array) DB::table('products')->find($products[$path]->id);
        $proofs = [];
        foreach ($children as $childPath => $cell) {
            $child = (array) DB::table('products')->find($products[$childPath]->id);
            $child += ['note' => null];
            $proofs[] = ['path' => $childPath, 'cell' => $cell, 'value' => 1, 'expected_key' => $child['import_key'], 'existing_same_generation' => [$child]];
        }
        $record = ['product_id' => $p['id'], 'historical_type' => $path, 'old_import_key' => $p['import_key'],
            'product_updated_at' => $p['updated_at'], 'historical_phase_requires_review' => 'no', 'source_excel_sha256' => $sha,
            'protection_reason' => 'outside_confirmed_assortment', 'vehicle_generation_id' => $generation->id,
            'historical_make' => 'Example', 'historical_model' => 'Car', 'historical_generation' => '1', 'historical_years' => '2000-2005',
            'historical_body' => 'Седан 4 дв.', 'historical_platform' => '', 'historical_restyling' => '', 'historical_fitment_note' => '',
            'source_rows_and_vehicles' => json_encode($source), 'source_cells_and_successor_products' => json_encode($proofs)];
        if ($path === 'porog') {
            fputcsv($stream, array_keys($record), ';', '"', '');
        }
        fputcsv($stream, $record, ';', '"', '');
    }
    fclose($stream);
    $excluded = Storage::disk('local')->path('excluded.csv');
    file_put_contents($excluded, "product_id\n".$products['arka/karman-zadniaia']->id."\n");
    sort($parents);
    $decision = ['source_run_id' => $run->id, 'manifest_sha256' => hash_file('sha256', $csv), 'ids_sha256' => hash('sha256', json_encode($parents)),
        'excel_sha256' => $sha, 'excluded_path' => $excluded, 'excluded_sha256' => hash_file('sha256', $excluded),
        'counts' => ['porog' => 1, 'arka/vnutrenniaia' => 1]];
    $factory = app(ImportProductFactory::class);

    return compact('factory', 'csv', 'xlsx', 'decision', 'run', 'products', 'parents', 'variant');
}

function recurringStructuralRun(array $f, bool $start = true): ImportRun
{
    $run = ImportRun::factory()->create(['type' => 'catalog', 'import_mode' => 'full', 'status' => ImportRunStatus::Ready,
        'stored_path' => 'structural.xlsx', 'file_hash' => hash_file('sha256', $f['xlsx']),
        'configuration_path' => 'matrix.csv', 'configuration_hash' => hash_file('sha256', Storage::disk('local')->path('matrix.csv'))]);
    $installation = app(CatalogConfigurationInstallation::class);
    $review = $installation->previewImport($run);
    $installation->confirmImport($run, User::factory()->superAdmin()->create(), $review['confirmation']);
    if ($start) {
        app()->call([new CatalogImportStartJob($run->id), 'handle']);
        if ($run->fresh()->status === ImportRunStatus::Failed) {
            throw new RuntimeException($run->fresh()->last_error);
        }
    }

    return $run->fresh();
}

function finishRecurringStructural(ImportRun $run): ImportRun
{
    for ($i = 0; $i < 5 && $run->fresh()->status?->isRowsRunning(); $i++) {
        app()->call([new CatalogImportChunkJob($run->id), 'handle']);
    }
    $run->refresh();
    expect($run->status, $run->last_error ?? '')->toBe(ImportRunStatus::Done);

    return $run;
}

test('ordinary FULL previews retires and replays generic cards without a batch or changes to protected variants manual holds and prior FULL', function (): void {
    $f = structuralFixture();
    $before = DB::table('products')->whereIn('id', $f['parents'])->orderBy('id')->get()->keyBy('id');
    $prior = (array) DB::table('import_runs')->find($f['run']->id);
    $cp = DB::table('catalog_import_protections')->get()->toJson();
    $variants = DB::table('product_variants')->whereIn('product_id', $f['parents'])->get()->toJson();
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    expect(array_keys($plan['full_scope']['archives']))->toEqualCanonicalizing($f['parents'])
        ->and($plan['execution_counts']['structural_archive'])->toBe(2);
    $run = finishRecurringStructural(recurringStructuralRun($f));
    expect($run->archived_products)->toBe(2);
    foreach ($f['parents'] as $id) {
        $after = (array) DB::table('products')->find($id);
        expect($after['status'])->toBe('archived')->and((bool) $after['import_present'])->toBeFalse();
        expect(array_diff_key($after, array_flip(['status', 'import_present', 'updated_at'])))
            ->toBe(array_diff_key((array) $before[$id], array_flip(['status', 'import_present', 'updated_at'])));
    }
    expect(DB::table('catalog_import_protections')->get()->toJson())->toBe($cp)
        ->and(DB::table('product_variants')->whereIn('product_id', $f['parents'])->get()->toJson())->toBe($variants)
        ->and((array) DB::table('import_runs')->find($f['run']->id))->toBe($prior)
        ->and($f['products']['arka/vnutrenniaia-universalnaia']->fresh()->status->value)->toBe('draft')
        ->and($f['products']['arka/karman-zadniaia']->fresh()->status->value)->toBe('active')
        ->and(Schema::hasTable('catalog_structural_archive_batches'))->toBeFalse();
    $ids = Product::orderBy('id')->pluck('id')->all();
    expect(app(CatalogImportProductPlan::class)->full($f['xlsx'])['execution_counts']['structural_archive'])->toBe(0);
    $again = finishRecurringStructural(recurringStructuralRun($f));
    expect($again->created_products)->toBe(0)->and($again->archived_products)->toBe(0)
        ->and(Product::orderBy('id')->pluck('id')->all())->toBe($ids);
});

test('ordinary FULL can plan a new specific offer but requires it to exist before structural publication', function (): void {
    $f = structuralFixture();
    $oldId = $f['products']['porog/uvelichennyi']->id;
    DB::table('products')->where('id', $oldId)->delete();
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    expect($plan['execution_counts']['structural_archive'])->toBe(2)
        ->and($plan['full_scope']['archives'][$f['parents'][0]]['evidence']['counterparts'][0]['product_id'])->toBeNull();
    $run = finishRecurringStructural(recurringStructuralRun($f));
    $new = Product::where('import_key', $f['products']['porog/uvelichennyi']->import_key)->firstOrFail();
    expect($new->id)->not->toBe($f['parents'][0])->and($run->created_products)->toBe(1)->and($run->archived_products)->toBe(2);
});

test('structural FULL keeps generic offers if a successor relation is ambiguous or a parent has a manual hold', function (string $case): void {
    $f = structuralFixture();
    if ($case === 'relation') {
        DB::table('product_fitments')->where('product_id', $f['products']['porog/uvelichennyi']->id)->update(['note' => 'Different applicability']);
    } else {
        // Set the administrative hold before permanent protection is established in this isolated fixture.
        $p = Product::factory()->create(['part_type_id' => PartType::where('full_slug', 'porog')->value('id'),
            'import_source' => 'catalog', 'import_key' => 'manual-extra', 'status' => 'draft', 'publication_override' => 'draft']);
        DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $f['products']['porog']->fitments()->value('vehicle_generation_id'), 'is_primary' => true]);
    }
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    if ($case === 'relation') {
        expect(array_keys($plan['full_scope']['archives']))->not->toContain($f['parents'][0])
            ->and($plan['full_scope']['preserve'][$f['parents'][0]])->toBe('structural_successor_requires_decision');
    } else {
        expect($plan['full_scope']['preserve'][$p->id])->toBe('manual_publication_hold');
    }
})->with(['relation', 'manual']);

test('specific source restyling without historical phase is shown as a structural exception', function (): void {
    $f = structuralFixture();
    $writer = new Writer;
    $writer->openToFile($f['xlsx']);
    $writer->addRow(Row::fromValues(['', '', '', '', '', '', 'Порог', 'Порог', 'Арка', 'Арка', '', '']));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Стандартный', 'Увеличенный', 'Внутренняя задняя', 'Внутренняя передняя', 'Барабан', 'Рестайлинг']));
    $writer->addRow(Row::fromValues(['', 'Example', 'Car', '1', '2000-2005', 'Седан 4 двери', '', 1, 1, '', 1, '1 дорест']));
    $writer->close();
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    expect($plan['execution_counts']['structural_archive'])->toBe(0)
        ->and($plan['structural_counts']['held_by_reason']['structural_restyling_requires_decision'])->toBe(2);
});

test('a successor changed after confirmation prevents the atomic FULL publication', function (): void {
    $f = structuralFixture();
    $run = recurringStructuralRun($f);
    expect($run->status)->toBe(ImportRunStatus::RunningRows);
    $snapshot = DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->get();
    foreach ($snapshot as $item) {
        DB::table('catalog_import_snapshot_items')->where('id', $item->id)->update(['product_id' => Product::where('import_key', $item->import_key)->value('id')]);
    }
    $run->forceFill(['processed_rows' => $run->total_rows, 'current_row' => $run->total_rows])->save();
    DB::table('product_fitments')->where('product_id', $f['products']['porog/uvelichennyi']->id)->update(['note' => 'Changed after consent']);
    expect(fn () => app(CatalogImportSnapshot::class)->finalize($run, fn () => $f['factory']->archiveMissingProducts($run)))
        ->toThrow(RuntimeException::class);
    expect(DB::table('products')->whereIn('id', $f['parents'])->where('status', 'active')->count())->toBe(2);
});

test('tampering with structural FULL scope does not grant archive authority', function (): void {
    $f = structuralFixture();
    $run = recurringStructuralRun($f);
    $prepared = $run->prepared_rows;
    $prepared['full_scope']['archives'][$f['products']['arka/karman-zadniaia']->id] = reset($prepared['full_scope']['archives']);
    $run->forceFill(['prepared_rows' => $prepared])->save();
    expect(fn () => app(CatalogImportSnapshot::class)->validateFullScope($run))->toThrow(RuntimeException::class);
    expect($f['products']['arka/karman-zadniaia']->fresh()->status->value)->toBe('active');
});

test('structural FULL retains the existing 54 guards and has no batch guard migration', function (): void {
    $names = DB::getDriverName() === 'mysql'
        ? DB::table('information_schema.triggers')->where('trigger_schema', DB::connection()->getDatabaseName())->where('trigger_name', 'like', 'cip_%')->selectRaw('TRIGGER_NAME AS name')->pluck('name')->all()
        : DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'cip_%')->pluck('name')->all();
    expect($names)->toHaveCount(54)->and(array_values(array_filter($names, fn ($n) => str_ends_with($n, '_archive_v3'))))->toHaveCount(1);
});

test('structural proof changes invalidate the usual FULL confirmation before initialization', function (): void {
    $f = structuralFixture();
    $run = recurringStructuralRun($f, false);
    DB::table('product_fitments')->where('product_id', $f['products']['porog/uvelichennyi']->id)->update(['note' => 'Changed after preview']);
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    expect($run->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($run->fresh()->initialized_at)->toBeNull()
        ->and(DB::table('products')->whereIn('id', $f['parents'])->where('status', 'active')->count())->toBe(2);
});

test('an unfamiliar positive descendant is not silently covered by another specific offer', function (): void {
    $f = structuralFixture();
    $rows = iterator_to_array(app(SpreadsheetReader::class)->catalogRows($f['xlsx']));
    $row = $rows[0]['row'];
    $row['_details'][11] = 1;
    $parent = $f['products']['porog']->load('fitments', 'partType');
    $generation = VehicleGeneration::findOrFail($parent->fitments->first()->vehicle_generation_id);
    $child = $f['products']['porog/uvelichennyi']->load('fitments', 'partType');
    $proof = $f['factory']->proveStructuralRetirement($parent, $generation, [3 => $row],
        ['porog/uvelichennyi' => [7], 'porog/osobyi' => [11]],
        [3 => ['porog/uvelichennyi' => ['key' => $child->import_key, 'generation_id' => $generation->id, 'executable' => true]]], collect([$child->import_key => $child]));
    expect($proof['proof'])->toBeNull()->and($proof['reason'])->toBe('structural_type_requires_mapping');
});
