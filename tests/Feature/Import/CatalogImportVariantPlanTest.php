<?php

use App\Models\CatalogConfiguration;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductOptionTemplate;
use App\Services\Catalog\CatalogConfigurationInstallation;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\Catalog\CatalogVariantDifference;
use App\Services\Import\CatalogImportProductPlan;
use App\Services\Import\CatalogImportVariantPlan;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    Bus::fake();
    Http::preventStrayRequests();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    $this->planner = app(CatalogImportVariantPlan::class);
});

function variantPlanCsv(array $offers = [['Левый', '1000'], ['Правый', '1200']], string $type = 'Усилитель'): string
{
    $path = Storage::disk('local')->path(uniqid('variant-plan-').'.csv');
    $file = fopen($path, 'wb');
    fputcsv($file, ['Название', 'Профиль', 'Положение', 'Материал', 'Толщина металла', 'Цена'], escape: '');
    foreach ($offers as [$position, $price]) {
        fputcsv($file, [$type, '-', $position, 'ХКС', '1 мм', $price], escape: '');
    }
    fclose($file);

    return $path;
}

function variantPlanInstall(string $csv): void
{
    $review = app(CatalogConfigurationInstallation::class)->preview(app(CatalogConfigurationSource::class)->read($csv));
    app(CatalogConfigurations::class)->install($csv, $review['confirmation']);
}

function variantPlanProduct(array $attributes = [], bool $synchronize = true): Product
{
    $product = Product::factory()->create(array_replace([
        'part_type_id' => PartType::where('full_slug', 'usilitel')->value('id'),
        'import_source' => 'catalog', 'import_key' => uniqid('catalog:variant-plan:'),
        'price_mode' => 'unknown', 'product_option_template_id' => null,
    ], $attributes));
    if ($synchronize) {
        app(CatalogConfigurations::class)->synchronize($product);
    }

    return $product->fresh();
}

function variantPlanExcel(): array
{
    $path = Storage::disk('local')->path(uniqid('variant-plan-').'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues(array_fill(0, 7, null)));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Усилитель']));
    $writer->addRow(Row::fromValues([null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1]));
    $writer->close();

    return [$path, app(CatalogImportProductPlan::class)->plan($path)];
}

function variantPlanState(): array
{
    $database = collect(DB::getSchemaBuilder()->getTableListing())->mapWithKeys(
        fn ($table) => [$table => DB::table($table)->get()->toJson()],
    )->all();
    $files = [];
    foreach (['local', 'public'] as $disk) {
        foreach (Storage::disk($disk)->allFiles() as $path) {
            $files[$disk.':'.$path] = hash_file('sha256', Storage::disk($disk)->path($path));
        }
    }

    return [$database, $files];
}

test('full projects all CSV offers for a new Excel product without an installed matrix', function (): void {
    [, $excel] = variantPlanExcel();
    $csv = variantPlanCsv();
    $plan = $this->planner->plan($csv, 'full', $excel);
    expect($plan['valid'])->toBeTrue()->and($plan['purpose'])->toBe('projected_variant_diff')
        ->and($plan['source_hashes'])->toBe(['csv' => hash_file('sha256', $csv), 'excel' => $excel['file']['sha256']])
        ->and($plan['counts'])->toMatchArray(['products_scanned' => 1, 'products_new' => 1, 'products_changed' => 1,
            'variants_create' => 2, 'variants_update' => 0, 'product_prices_change' => 0, 'default_changes' => 1])
        ->and(Product::count())->toBe(0)->and(CatalogConfiguration::count())->toBe(0);
});

test('matching variants are retained and repeated projection does not propose duplicates', function (): void {
    $csv = variantPlanCsv();
    variantPlanInstall($csv);
    variantPlanProduct();
    $first = $this->planner->plan($csv);
    expect($first['valid'])->toBeTrue()->and($first['counts'])->toMatchArray([
        'products_scanned' => 1, 'products_changed' => 0, 'variants_create' => 0,
        'variants_update' => 0, 'variants_unchanged' => 2, 'variant_prices_change' => 0,
    ])->and($this->planner->plan($csv))->toBe($first);
});

test('new CSV prices override installed and individual prices including ownership mode', function (string $mode, string $price): void {
    variantPlanInstall(variantPlanCsv());
    $product = variantPlanProduct();
    $variant = $product->variants()->orderBy('id')->first();
    DB::table('product_variants')->where('id', $variant->id)->update(['price' => $price, 'price_mode' => $mode]);
    $csv = variantPlanCsv([['Левый', '1500'], ['Правый', '1200']]);
    $plan = $this->planner->plan($csv);
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray([
        'variants_create' => 0, 'variants_update' => 1, 'variant_prices_change' => 1,
        'product_prices_change' => 1, 'individual_prices_overridden' => $mode === 'inherited' ? 0 : 1,
    ])->and($variant->fresh()->price)->toBe($price);
    variantPlanInstall($csv);
    $applied = app(CatalogConfigurations::class)->synchronize($product->fresh());
    expect($applied['prices_to_change'])->toBe($plan['counts']['variant_prices_change'])
        ->and($applied['product_prices_to_change'])->toBe($plan['counts']['product_prices_change'])
        ->and($product->fresh()->price)->toBe('1500.00');
})->with([['inherited', '1000.00'], ['individual', '777.00'], ['unknown', '1500.00']]);

test('removed default configuration retires its offer and selects the remaining default', function (): void {
    variantPlanInstall(variantPlanCsv());
    $product = variantPlanProduct();
    $csv = variantPlanCsv([['Правый', '1200']]);
    $plan = $this->planner->plan($csv);
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray([
        'variants_retire' => 1, 'variants_update' => 1, 'variants_unchanged' => 0,
        'variants_create' => 0, 'product_prices_change' => 1, 'default_changes' => 2,
    ]);
    variantPlanInstall($csv);
    app(CatalogConfigurations::class)->synchronize($product->fresh());
    expect($product->variants()->where('is_active', true)->count())->toBe(1)
        ->and($product->fresh()->price)->toBe('1200.00');
});

test('added configuration creates one offer and preserves the existing default even if dearer', function (): void {
    variantPlanInstall(variantPlanCsv([['Левый', '1000']]));
    variantPlanProduct();
    $plan = $this->planner->plan(variantPlanCsv([['Левый', '1000'], ['Правый', '500']]));
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray([
        'variants_create' => 1, 'variants_update' => 0, 'variants_unchanged' => 1,
        'variant_prices_change' => 0, 'product_prices_change' => 0, 'default_changes' => 0,
    ]);
});

test('only an automatically retired offer is reactivated', function (bool $auto): void {
    $csv = variantPlanCsv();
    variantPlanInstall($csv);
    $product = variantPlanProduct();
    $variant = $product->variants()->orderByDesc('id')->first();
    DB::table('product_variants')->where('id', $variant->id)->update(['is_active' => false, 'configuration_auto_retired' => $auto]);
    DB::table('catalog_configurations')->where('id', $variant->catalog_configuration_id)->update(['is_active' => false]);
    $plan = $this->planner->plan($csv);
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray([
        'variants_create' => 0, 'variants_reactivate' => $auto ? 1 : 0,
        'variants_update' => $auto ? 1 : 0, 'variants_unchanged' => $auto ? 1 : 2,
    ]);
    variantPlanInstall($csv);
    app(CatalogConfigurations::class)->synchronize($product->fresh());
    expect($variant->fresh()->is_active)->toBe($auto);
})->with([true, false]);

test('historical snapshot with localized values matches by normalized signature without configuration ID', function (): void {
    $csv = variantPlanCsv();
    variantPlanInstall($csv);
    $product = variantPlanProduct();
    $variant = $product->variants()->first();
    DB::table('product_variants')->where('id', $variant->id)->update(['catalog_configuration_id' => null]);
    DB::table('product_variant_option_values')->where('product_variant_id', $variant->id)->delete();
    $plan = $this->planner->plan($csv);
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray([
        'variants_create' => 0, 'variants_update' => 1, 'variants_unchanged' => 1,
    ]);
});

test('duplicate variant signatures produce uncertainty and a bounded diagnostic instead of choosing an ID', function (): void {
    $csv = variantPlanCsv();
    variantPlanInstall($csv);
    $product = variantPlanProduct();
    $attributes = $product->variants()->first()->getRawOriginal();
    unset($attributes['id']);
    $attributes['catalog_configuration_id'] = null;
    $attributes['sku'] = null;
    DB::table('product_variants')->insert($attributes);
    $plan = $this->planner->plan($csv);
    expect($plan['valid'])->toBeFalse()->and($plan['counts'])->toMatchArray([
        'conflicts' => 1, 'products_unresolved' => 1, 'variants_create' => null, 'variants_update' => null,
    ])->and($plan['diagnostics']['samples'][0])->toMatchArray(['code' => 'duplicate_signature', 'product_id' => $product->id]);
});

test('foreign configuration bindings are conflicts even when their row is absent from the new CSV', function (): void {
    $csv = variantPlanCsv();
    variantPlanInstall($csv);
    $product = variantPlanProduct();
    $variant = $product->variants()->first();
    $linked = CatalogConfiguration::findOrFail($variant->catalog_configuration_id);
    DB::table('catalog_configurations')->where('id', $linked->id)->update([
        'part_type_id' => PartType::where('full_slug', 'soedinitel')->value('id'), 'is_active' => false,
    ]);
    $plan = $this->planner->plan($csv);
    expect($plan['valid'])->toBeFalse()->and($plan['counts']['conflicts'])->toBe(1)
        ->and($plan['counts']['variant_prices_change'])->toBeNull()
        ->and($plan['diagnostics']['samples'][0]['code'])->toBe('foreign_configuration');
});

test('custom product templates are protected including a type removed from CSV', function (bool $removed): void {
    variantPlanInstall(variantPlanCsv());
    $product = variantPlanProduct();
    $template = ProductOptionTemplate::create(['title' => 'Custom', 'slug' => 'custom-variant-plan', 'applies_to' => 'auto_part', 'is_active' => true, 'is_default' => false]);
    DB::table('products')->where('id', $product->id)->update(['product_option_template_id' => $template->id]);
    $plan = $this->planner->plan($removed ? variantPlanCsv([['Левый', '900']], 'Соединитель') : variantPlanCsv());
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray([
        'protected_products' => 1, 'products_changed' => 0, 'variants_retire' => 0,
        'variants_update' => 0, 'variant_prices_change' => 0, 'variants_unchanged' => 2,
    ]);
})->with([false, true]);

test('CSV-only scans removed types using the job scope and excludes deleted or unrelated products', function (): void {
    variantPlanInstall(variantPlanCsv());
    variantPlanProduct();
    $deleted = variantPlanProduct();
    DB::table('products')->where('id', $deleted->id)->update(['deleted_at' => now()]);
    variantPlanProduct(['import_source' => 'other']);
    variantPlanProduct(['import_key' => null]);
    $plan = $this->planner->plan(variantPlanCsv([['Левый', '900']], 'Соединитель'));
    expect($plan['valid'])->toBeTrue()->and($plan['scope'])->toBe('csv')
        ->and($plan['source_hashes']['excel'])->toBeNull()
        ->and($plan['counts'])->toMatchArray(['products_scanned' => 1, 'variants_retire' => 2, 'variants_create' => 0])
        ->and($plan['by_type']['usilitel']['counts']['variants_retire'])->toBe(2);
});

test('full returns archived and deleted expected products while absent products only affect assortment', function (?string $override, bool $deleted): void {
    [$xlsx, $excel] = variantPlanExcel();
    variantPlanInstall(variantPlanCsv());
    $product = variantPlanProduct(['import_key' => $excel['expected_products'][0]['import_key'],
        'status' => $override === 'draft' ? 'draft' : 'archived', 'publication_override' => $override]);
    if ($deleted) {
        DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]);
    }
    variantPlanProduct(['status' => 'active']);
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    $before = variantPlanState();
    $plan = $this->planner->plan(variantPlanCsv([['Левый', '2000'], ['Правый', '1200']]), 'full', $excel);
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray([
        'products_scanned' => 1, 'products_new' => 0, 'variant_prices_change' => 1,
    ])->and($plan['assortment']['counts']['missing'])->toBe(1)
        ->and($plan['assortment']['counts']['restore_deleted'])->toBe($deleted ? 1 : 0)
        ->and($plan['assortment']['counts']['preserve_manual'])->toBe($override ? 1 : 0)
        ->and(variantPlanState()[0])->toBe($before[0]);
})->with([[null, false], [null, true], ['archived', true], ['draft', false]]);

test('read-only projection is repeatable and performs no writes file changes dispatch or HTTP', function (): void {
    [$xlsx, $excel] = variantPlanExcel();
    $csv = variantPlanCsv();
    variantPlanInstall($csv);
    variantPlanProduct(['import_key' => $excel['expected_products'][0]['import_key']]);
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    $changed = variantPlanCsv([['Левый', '2000'], ['Правый', '1200']]);
    $before = variantPlanState();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    foreach (['csv', 'full'] as $scope) {
        $plan = $this->planner->plan($changed, $scope, $scope === 'full' ? $excel : null);
        expect($plan['valid'])->toBeTrue()->and($this->planner->plan($changed, $scope, $scope === 'full' ? $excel : null))->toBe($plan);
    }
    expect($writes)->toBe([])->and(variantPlanState())->toBe($before);
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('shared calculation uses loaded data only and keeps the installed comparison contract', function (): void {
    variantPlanInstall(variantPlanCsv());
    $product = variantPlanProduct()->load('variants.optionValues.group');
    $rows = app(CatalogConfigurations::class)->rows($product->part_type_id);
    $expected = app(CatalogConfigurations::class)->changes($product);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $actual = app(CatalogVariantDifference::class)->compare($product, $rows, false);
    expect($actual)->toBe($expected)->and($queries)->toBe([]);
});

test('missing type or disabled options make application totals unknown', function (string $damage): void {
    [, $excel] = variantPlanExcel();
    if ($damage === 'type') {
        DB::table('part_types')->where('full_slug', 'usilitel')->update(['full_slug' => 'historical-usilitel']);
    } else {
        DB::table('product_option_groups')->where('code', 'position')->update(['is_active' => false]);
    }
    $plan = $this->planner->plan(variantPlanCsv(), 'full', $excel);
    expect($plan['valid'])->toBeFalse()->and($plan['counts']['variants_create'])->toBeNull()
        ->and($plan['counts']['products_unresolved'])->toBe(1);
})->with(['type', 'option']);

test('diagnostic samples stay bounded across multiple product batches', function (): void {
    $csv = variantPlanCsv();
    variantPlanInstall($csv);
    $template = ProductOptionTemplate::create(['title' => 'Custom', 'slug' => 'custom-batch', 'applies_to' => 'auto_part', 'is_default' => false]);
    for ($i = 0; $i < 52; $i++) {
        variantPlanProduct(['product_option_template_id' => $template->id], false);
    }
    $plan = $this->planner->plan($csv);
    expect($plan['valid'])->toBeTrue()->and($plan['counts']['products_scanned'])->toBe(52)
        ->and($plan['counts']['protected_products'])->toBe(52)
        ->and($plan['diagnostics']['samples'])->toHaveCount(25)->and($plan['diagnostics']['truncated'])->toBe(27);
});
