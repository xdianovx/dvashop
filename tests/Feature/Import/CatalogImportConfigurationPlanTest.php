<?php

use App\Models\PartType;
use App\Models\Product;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\Import\CatalogImportConfigurationPlan;
use App\Services\Import\CatalogImportProductPlan;
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
    $this->projection = app(CatalogImportConfigurationPlan::class);
});

function configurationPlanExcel(array $details = ['Усилитель'], ?array $rows = null): string
{
    $path = Storage::disk('local')->path(uniqid('configuration-plan-').'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues(array_fill(0, 6 + count($details), null)));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', ...$details]));
    foreach ($rows ?? [array_fill(0, count($details), 1)] as $cells) {
        $writer->addRow(Row::fromValues([null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', ...$cells]));
    }
    $writer->close();

    return $path;
}

function configurationPlanCsv(?array $rows = null): string
{
    $path = Storage::disk('local')->path(uniqid('configuration-plan-').'.csv');
    $file = fopen($path, 'wb');
    fputcsv($file, ['Название', 'Профиль', 'Положение', 'Материал', 'Толщина металла', 'Цена'], escape: '');
    foreach ($rows ?? [['Усилитель', '-', 'Левый', 'ХКС', '1 мм', '1234.56']] as $row) {
        fputcsv($file, $row, escape: '');
    }
    fclose($file);

    return $path;
}

function configurationPlanProduct(array $excel, array $attributes = []): Product
{
    return Product::factory()->create(array_replace([
        'part_type_id' => PartType::where('full_slug', 'usilitel')->value('id'),
        'import_source' => 'catalog', 'import_key' => $excel['expected_products'][0]['import_key'],
        'status' => 'active', 'price_mode' => 'unknown', 'slug' => 'preserved-product-url',
    ], $attributes));
}

function configurationPlanDatabase(): array
{
    return collect(DB::getSchemaBuilder()->getTableListing())->mapWithKeys(
        fn (string $table): array => [$table => DB::table($table)->get()->toJson()],
    )->all();
}

function configurationPlanFiles(): array
{
    $files = [];
    foreach (['local', 'public'] as $disk) {
        foreach (Storage::disk($disk)->allFiles() as $path) {
            $files[$disk.':'.$path] = hash_file('sha256', Storage::disk($disk)->path($path));
        }
    }

    return $files;
}

test('new and existing Excel products reference projected configurations without duplicating them', function (bool $existing): void {
    $xlsx = configurationPlanExcel();
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    $product = $existing ? configurationPlanProduct($excel) : null;
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    $csv = configurationPlanCsv();
    $plan = $this->projection->plan($excel, $csv);
    $item = $plan['expected_products'][0];
    expect($plan['purpose'])->toBe('projected_configuration_only')
        ->and($plan['valid'])->toBeTrue()->and($plan['diagnostics']['errors'])->toBe([])
        ->and($plan['files']['excel'])->toBe($excel['file'])
        ->and($plan['files']['csv']['sha256'])->toBe(hash_file('sha256', $csv))
        ->and($item)->toMatchArray(['import_key' => $excel['expected_products'][0]['import_key'],
            'part_type_full_slug' => 'usilitel', 'product_id' => $product?->id,
            'action' => $existing ? 'keep' : 'create', 'matrix_type' => 'usilitel', 'configuration_count' => 1])
        ->and($item)->not->toHaveKey('configurations');
    $signature = CatalogConfigurationSource::signature(['position' => 'left', 'material' => 'cold_rolled', 'thickness' => '1mm']);
    expect($plan['matrix'][$item['matrix_type']]['configurations'][$signature])->toBe([
        'options' => ['position' => 'left', 'material' => 'cold_rolled', 'thickness' => '1mm'],
        'price' => '1234.56', 'source_group' => 'Усилитель',
    ]);
})->with([false, true]);

test('new CSV prices and options replace neither installed matrix nor historical product prices', function (): void {
    $installed = configurationPlanCsv([['Усилитель', '-', 'Левый', 'ХКС', '1 мм', '1000']]);
    app(CatalogConfigurations::class)->install($installed);
    $xlsx = configurationPlanExcel();
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    $product = configurationPlanProduct($excel, ['price' => '777.00', 'price_mode' => 'individual']);
    $before = configurationPlanDatabase();
    $csv = configurationPlanCsv([
        ['Усилитель', '-', 'Левый', 'ХКС', '1 мм', '2345.67'],
        ['Усилитель', '-', 'Правый', 'Оцинковка', '1,5 мм', '3456.78'],
    ]);
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan($xlsx), $csv);
    expect($plan['valid'])->toBeTrue()
        ->and(array_column($plan['matrix']['usilitel']['configurations'], 'price'))->toBe(['2345.67', '3456.78'])
        ->and($plan['expected_products'][0]['configuration_count'])->toBe(2)
        ->and(configurationPlanDatabase())->toBe($before)
        ->and($product->refresh()->price)->toBe('777.00');
    $changed = configurationPlanCsv([['Усилитель', '-', 'Правый', 'Оцинковка', '1,5 мм', '4567.89']]);
    $revised = $this->projection->plan($excel, $changed);
    $signature = CatalogConfigurationSource::signature(['position' => 'right', 'material' => 'galvanized', 'thickness' => '1_5mm']);
    expect(array_keys($revised['matrix']['usilitel']['configurations']))->toBe([$signature])
        ->and($revised['matrix']['usilitel']['configurations'][$signature]['price'])->toBe('4567.89')
        ->and(configurationPlanDatabase())->toBe($before);
});

test('missing CSV coverage includes types from zero-positive and unsupported Excel columns', function (string $title, string $slug, int $presence): void {
    $xlsx = configurationPlanExcel(['Усилитель', $title], [[1, $presence]]);
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    $plan = $this->projection->plan($excel, configurationPlanCsv());
    expect($excel['required_part_types'])->toBe(['usilitel', $slug])
        ->and($plan['valid'])->toBeFalse()
        ->and($plan['diagnostics']['errors'])->toHaveCount(1)
        ->and($plan['diagnostics']['errors'][0])->toMatchArray(['code' => 'missing_type', 'part_type_full_slug' => $slug]);
    if ($presence === 1) {
        $missing = collect($plan['expected_products'])->firstWhere('part_type_full_slug', $slug);
        expect($missing['matrix_type'])->toBeNull()->and($missing['configuration_count'])->toBeNull();
    }
})->with([
    ['Соединитель порогов', 'soedinitel', 0],
    ['Соединитель порогов', 'soedinitel', 1],
    ['Новая деталь', 'novaia-detal', 0],
]);

test('duplicate Excel rows keep OR assortment and reference a single matrix per type', function (): void {
    $xlsx = configurationPlanExcel(['Усилитель', 'Соединитель порогов'], [[1, 0], [0, true], [1, 'maver']]);
    $csv = configurationPlanCsv([
        ['Усилитель', '-', 'Левый', 'ХКС', '1 мм', '1200'],
        ['Соединитель', '-', 'Правый', 'ХКС', '1 мм', '1300'],
    ]);
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan($xlsx), $csv);
    expect($plan['valid'])->toBeTrue()->and($plan['expected_products'])->toHaveCount(2)
        ->and(array_unique(array_column($plan['expected_products'], 'import_key')))->toHaveCount(2)
        ->and($plan['matrix'])->toHaveCount(2)
        ->and($plan['diagnostics']['excel'])->toMatchArray(['duplicate_vehicle_rows' => 2, 'duplicate_positive_cells' => 1]);
});

test('one inner arch CSV group projects to all three independent types without reaching the drum', function (): void {
    $xlsx = configurationPlanExcel(['Арка / Внутренняя', 'Арка / Внутренняя передняя', 'Арка / Внутренняя задняя']);
    $csv = configurationPlanCsv([
        ['Арка внутренняя', '-', 'Левый', 'ХКС', '1 мм', '2100'],
        ['Арка внутренняя', '-', 'Правый', 'ХКС', '1 мм', '2200'],
    ]);
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan($xlsx), $csv);
    expect($plan['valid'])->toBeTrue()->and($plan['expected_products'])->toHaveCount(3)
        ->and($plan['matrix'])->toHaveCount(3)
        ->and($plan['matrix'])->not->toHaveKey('arka/vnutrenniaia-universalnaia');
    foreach (['arka/vnutrenniaia', 'arka/vnutrenniaia-peredniaia', 'arka/vnutrenniaia-zadniaia'] as $type) {
        expect($plan['matrix'][$type]['configuration_count'])->toBe(2)
            ->and(array_column($plan['matrix'][$type]['configurations'], 'price'))->toBe(['2100.00', '2200.00'])
            ->and(collect($plan['expected_products'])->firstWhere('part_type_full_slug', $type)['matrix_type'])->toBe($type);
    }
});

test('manual archive and draft actions remain unchanged by configuration projection', function (string $status): void {
    $xlsx = configurationPlanExcel();
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    $product = configurationPlanProduct($excel, ['status' => $status, 'publication_override' => $status]);
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan($xlsx), configurationPlanCsv());
    expect($plan['valid'])->toBeTrue()->and($plan['expected_products'][0])->toMatchArray([
        'product_id' => $product->id, 'slug' => $product->slug, 'current_status' => $status,
        'publication_override' => $status, 'action' => 'preserve_manual', 'configuration_count' => 1,
    ])->and($product->refresh()->status->value)->toBe($status);
})->with(['archived', 'draft']);

test('all 106 source rows retain exact signatures options and prices across existing target mappings', function (): void {
    $csv = base_path('tests/Fixtures/catalog-configurations.csv');
    $rows = app(CatalogConfigurationSource::class)->read($csv);
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan(configurationPlanExcel()), $csv);
    // This fixture tests expansion of all 106 CSV rows, not full-import compatibility:
    // the tiny XLSX fixture contains only the 'Усилитель' column.
    expect($plan['valid'])->toBeFalse()
        ->and($plan['diagnostics']['errors'][0]['code'])->toBe('incompatible_sources')
        ->and($rows)->toHaveCount(106);
    foreach ($rows as $row) {
        foreach (CatalogConfigurationSource::targetPaths($row) as $type) {
            expect($plan['matrix'][$type]['configurations'][$row['signature']])->toBe([
                'options' => $row['options'], 'price' => $row['price'], 'source_group' => $row['group'],
            ]);
        }
    }
    expect($plan['matrix']['porog']['configuration_count'])->toBe(24)
        ->and($plan['matrix']['porog/standartnyi']['configuration_count'])->toBe(12)
        ->and($plan['matrix']['porog/uvelichennyi']['configuration_count'])->toBe(12)
        ->and($plan['matrix']['arka/vnutrenniaia-universalnaia']['configuration_count'])->toBe(6);
});

test('CSV groups without any matching Excel column fail the same source contract as full import', function (): void {
    $xlsx = configurationPlanExcel(['Усилитель']);
    $csv = configurationPlanCsv([
        ['Усилитель', '-', 'Левый', 'ХКС', '1 мм', '1200'],
        ['Соединитель', '-', 'Правый', 'ХКС', '1 мм', '1300'],
    ]);
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan($xlsx), $csv);
    expect($plan['valid'])->toBeFalse()
        ->and($plan['diagnostics']['errors'][0]['code'])->toBe('incompatible_sources')
        ->and($plan['diagnostics']['errors'][0]['message'])->toContain('CSV содержит группы без товарных колонок Excel')
        ->and($plan['matrix']['usilitel']['configuration_count'])->toBe(1);
});

test('empty option combinations are diagnosed instead of presented as purchasable configurations', function (): void {
    $csv = configurationPlanCsv([['Усилитель', '-', '-', '-', '-', '1000']]);
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan(configurationPlanExcel()), $csv);
    expect($plan['valid'])->toBeFalse()->and($plan['diagnostics']['errors'][0]['code'])->toBe('empty_configuration')
        ->and($plan['expected_products'][0]['matrix_type'])->toBeNull()
        ->and($plan['expected_products'][0]['configuration_count'])->toBeNull();
});

test('invalid CSV structure options or prices return explicit diagnostics without a partial matrix', function (string $kind): void {
    $csv = configurationPlanCsv([['Усилитель', '-', 'Левый', 'ХКС', $kind === 'options' ? '2 мм' : '1 мм', $kind === 'price' ? 'abc' : '1000']]);
    if ($kind === 'structure') {
        file_put_contents($csv, "wrong,header\n");
    }
    $plan = $this->projection->plan(app(CatalogImportProductPlan::class)->plan(configurationPlanExcel()), $csv);
    expect($plan['valid'])->toBeFalse()->and($plan['matrix'])->toBe([])
        ->and($plan['diagnostics']['errors'][0]['code'])->toBe('invalid_csv')
        ->and($plan['files']['csv']['sha256'])->toBe(hash_file('sha256', $csv));
})->with(['structure', 'options', 'price']);

test('old Excel plans without complete header coverage must be recalculated', function (): void {
    $excel = app(CatalogImportProductPlan::class)->plan(configurationPlanExcel());
    unset($excel['required_part_types']);
    $csv = configurationPlanCsv();
    expect(fn () => $this->projection->plan($excel, $csv))->toThrow(InvalidArgumentException::class, 'типами из заголовков');
});

test('repeated projection is deterministic and changes no database files or queues', function (): void {
    app(CatalogConfigurations::class)->install(configurationPlanCsv());
    $xlsx = configurationPlanExcel();
    $excel = app(CatalogImportProductPlan::class)->plan($xlsx);
    configurationPlanProduct($excel, ['price' => '777.00', 'price_mode' => 'individual']);
    $csv = configurationPlanCsv([['Усилитель', '-', 'Правый', 'ХКС', '1 мм', '9999']]);
    $before = configurationPlanDatabase();
    $files = configurationPlanFiles();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $first = $this->projection->plan(app(CatalogImportProductPlan::class)->plan($xlsx), $csv);
    $second = $this->projection->plan(app(CatalogImportProductPlan::class)->plan($xlsx), $csv);
    expect($first['valid'])->toBeTrue()->and($second)->toBe($first)
        ->and($writes)->toBe([])->and(configurationPlanDatabase())->toBe($before)
        ->and(configurationPlanFiles())->toBe($files);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});
