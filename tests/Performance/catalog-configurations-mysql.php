<?php

// Disposable MySQL only; no working credentials, volume or schema is used.
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CatalogActions;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\ProductVariantOptionGenerator;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/dvashop-config-benchmark-no-config.php',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'dvashop-config-mysql-test', 'DB_DATABASE' => 'dvashop_config_test',
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => 'config-test-only', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'SCOUT_DRIVER' => 'null', 'YANDEX_FEED_ENABLED' => 'false', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.mysql.host') !== 'dvashop-config-mysql-test'
    || config('database.connections.mysql.database') !== 'dvashop_config_test' || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Refusing non-disposable database.');
}
Bus::fake();
config(['scout.driver' => null, 'yandex-feed.enabled' => false]);
if (DB::select('show tables') !== [] || ! str_starts_with(DB::selectOne('select version() as v')->v, '8.4.')) {
    throw new RuntimeException('Requires EMPTY disposable MySQL 8.4.');
}
function measureMatrix(string $name, Closure $action): mixed
{
    $start = hrtime(true);
    // A single cumulative listener below measures deltas without retaining query bindings.
    $before = $GLOBALS['matrix_queries'];
    $result = $action();
    echo json_encode(['step' => $name, 'seconds' => round((hrtime(true) - $start) / 1e9, 3), 'queries' => $GLOBALS['matrix_queries'] - $before,
        'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)], JSON_UNESCAPED_UNICODE).PHP_EOL;

    return $result;
}
$GLOBALS['matrix_queries'] = 0;
DB::listen(function (): void {
    $GLOBALS['matrix_queries']++;
});
function matrixCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
measureMatrix('migrate isolated schema', fn () => Artisan::call('migrate', ['--force' => true]));
Artisan::call('db:seed', ['--class' => ProductCatalogSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => ProductOptionSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => PartTypeSeeder::class, '--force' => true]);
$admin = User::factory()->superAdmin()->create();
$type = PartType::where('full_slug', 'porog')->firstOrFail();
$template = ProductOptionTemplate::where('slug', 'default_auto_part')->firstOrFail();
$sample = Product::factory()->forPartType($type)->create(['product_option_template_id' => $template->id, 'price' => 100, 'price_mode' => 'unknown', 'status' => 'archived']);
app(ProductVariantOptionGenerator::class)->createMissingVariants($sample);
ProductVariant::factory()->create(['product_id' => $sample->id, 'price' => 777, 'price_mode' => 'individual', 'is_default' => false]);
$variants = DB::table('product_variants')->where('product_id', $sample->id)->orderBy('id')->get()->map(fn ($v) => (array) $v)->all();
$prototype = (array) DB::table('products')->find($sample->id);
$pivots = DB::table('product_variant_option_values')->get()->groupBy('product_variant_id');
matrixCheck(count($variants) === 25, 'Fixture requires 25 variants');
measureMatrix('seed 20000 products 500000 variants with option pivots', function () use ($prototype, $variants, $pivots): void {
    for ($start = 2; $start <= 20000; $start += 100) {
        $products = [];
        $newVariants = [];
        $links = [];
        for ($id = $start; $id < min(20001, $start + 100); $id++) {
            $products[] = array_replace($prototype, ['id' => $id, 'sku' => null, 'slug' => 'matrix-load-'.$id, 'title' => 'Нагрузочный товар '.$id, 'status' => $id <= 2000 ? 'archived' : 'active']);
            foreach ($variants as $i => $variant) {
                $variantId = ($id - 1) * 25 + $i + 1;
                $newVariants[] = array_replace($variant, ['id' => $variantId, 'product_id' => $id, 'sku' => null]);
                foreach ($pivots->get($variant['id'], collect()) as $pivot) {
                    $row = (array) $pivot;
                    unset($row['id']);
                    $row['product_variant_id'] = $variantId;
                    $links[] = $row;
                }
            }
        }
        DB::table('products')->insert($products);
        foreach (array_chunk($newVariants, 500) as $chunk) {
            DB::table('product_variants')->insert($chunk);
        }
        foreach (array_chunk($links, 500) as $chunk) {
            DB::table('product_variant_option_values')->insert($chunk);
        }
    }
});
matrixCheck(DB::table('product_variants')->count() === 500000, 'Wrong variant count');
$matrix = app(CatalogConfigurations::class);
measureMatrix('install CSV definitions only', fn () => $matrix->install(base_path('tests/Fixtures/catalog-configurations.csv')));
$actions = app(CatalogActions::class);
$p = ['kind' => 'configurations', 'type_id' => $type->id, 'descendants' => false, 'source_status' => 'archived', 'force' => false, 'configuration_id' => null, 'price' => null];
$preview = measureMatrix('preview 2000 products within 500000-variant catalog', fn () => $actions->preview($admin, $p));
echo json_encode(['preview' => $preview['counts']]).PHP_EOL;
$id = measureMatrix('confirm configuration migration', fn () => $actions->confirm($admin, $preview));
measureMatrix('first 100-product batch', fn () => $actions->batch($id, 0));
$cursor = (int) DB::table('catalog_actions')->find($id)->last_id;
$actions->batch($id, 0);
matrixCheck((int) DB::table('catalog_actions')->find($id)->last_id === $cursor, 'Duplicate delivery advanced cursor');
measureMatrix('resume remaining configuration batches', function () use ($actions, $id): void {
    while (in_array(($op = DB::table('catalog_actions')->find($id))->status, ['pending', 'running'])) {
        $actions->batch($id, (int) $op->last_id);
    }
});
matrixCheck(DB::table('product_variants')->where('id', '<=', 500000)->count() === 500000, 'Historical variants deleted');
matrixCheck(DB::table('product_variants')->where('product_id', '<=', 2000)->where('is_active', true)->count() === 48000, 'Wrong active configuration count');
matrixCheck(DB::table('product_variants')->where('price_mode', 'individual')->where('price', 777)->count() === 20000, 'Individual price lost');
$p = ['kind' => 'status', 'type_id' => $type->id, 'descendants' => true, 'source_status' => 'all', 'target_status' => 'draft', 'force' => false];
$preview = measureMatrix('status preview 20000 products', fn () => $actions->preview($admin, $p));
$id = measureMatrix('status confirmation', fn () => $actions->confirm($admin, $preview));
measureMatrix('apply status 20000 products', function () use ($actions, $id): void {
    while (in_array(($op = DB::table('catalog_actions')->find($id))->status, ['pending', 'running'])) {
        $actions->batch($id, (int) $op->last_id);
    }
});
matrixCheck(DB::table('products')->where('status', 'draft')->count() === 20000, 'Wrong final statuses');
echo json_encode(['result' => 'PASS', 'products' => DB::table('products')->count(), 'variants' => DB::table('product_variants')->count(), 'historical_ids_preserved' => 500000]).PHP_EOL;
