<?php

/**
 * Destructive only to an EMPTY, explicitly named disposable MySQL container.
 * Run: docker exec dvashop_app php tests/Performance/catalog-prices-mysql.php
 * No files, exports, working database credentials or working volumes are used.
 */
use App\Jobs\ApplyCatalogPrices;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CatalogBulkPrices;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'dvashop-pricing-mysql-test', 'DB_DATABASE' => 'dvashop_pricing_test',
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => 'pricing-test-only', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'SCOUT_DRIVER' => 'null', 'YANDEX_FEED_ENABLED' => 'false', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.mysql.host') !== 'dvashop-pricing-mysql-test'
    || config('database.connections.mysql.database') !== 'dvashop_pricing_test' || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Refusing to run outside the disposable pricing database.');
}
config(['yandex-feed.enabled' => false, 'scout.driver' => null]);
$realBus = Bus::getFacadeRoot();
Bus::fake();
$version = DB::selectOne('select version() as version')->version;
$paginationOnly = in_array('--pagination-only', $argv, true);
$faultsOnly = in_array('--faults-only', $argv, true);
if (! str_starts_with($version, '8.4.') || (! $paginationOnly && ! $faultsOnly && DB::select('show tables') !== [])) {
    throw new RuntimeException('Requires MySQL 8.4 with a completely empty disposable database.');
}
$queries = 0;
DB::listen(function ($event) use (&$queries): void {
    $queries++;
});
function measure(string $label, Closure $callback): mixed
{
    global $queries;
    $q = $queries;
    $start = hrtime(true);
    $result = $callback();
    echo json_encode(['step' => $label, 'seconds' => round((hrtime(true) - $start) / 1e9, 3), 'queries' => $queries - $q,
        'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)], JSON_UNESCAPED_UNICODE).PHP_EOL;

    return $result;
}
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function exceptionPageQuery(CatalogBulkPrices $service, array $ids): Builder
{
    return $service->exceptions($ids)->where('title', 'like', '%товар%')
        ->with(['partType', 'defaultVariant', 'fitments.generation.model.make'])
        ->withCount([
            'variants as individual_prices_count' => fn ($q) => $q->where('price_mode', 'individual'),
            'variants as unknown_prices_count' => fn ($q) => $q->where('price_mode', 'unknown'),
        ])->withCount('variants')->withMin('variants', 'price')->withMax('variants', 'price')->orderBy('id');
}
if ($paginationOnly) {
    check(DB::table('products')->count() === 20000 && DB::table('product_variants')->count() === 500000, 'Wrong fixture size');
    $query = exceptionPageQuery(app(CatalogBulkPrices::class), PartType::query()->pluck('id')->all());
    measure('full admin exception query page 1', fn () => $query->paginate(20));
    measure('full admin exception query page 100', fn () => $query->paginate(20, ['*'], 'page', 100));
    echo json_encode(['exception_plan' => DB::select('EXPLAIN '.$query->limit(20)->toSql(), $query->getBindings())]).PHP_EOL;
    exit(0);
}
if ($faultsOnly) {
    check(DB::table('products')->count() === 20000 && DB::table('product_variants')->count() === 500000, 'Wrong fixture size');
    $service = app(CatalogBulkPrices::class);
    $type = PartType::query()->whereHas('products')->orderBy('id')->firstOrFail();
    $admin = User::query()->firstOrFail();
    foreach (DB::table('catalog_price_operations')->whereIn('status', ['pending', 'running', 'paused'])->pluck('id') as $stopped) {
        $service->cancel($admin, $stopped);
    }
    // This connection deliberately points to an unused local port, never the working Redis.
    config(['database.redis.pricing_fault' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2],
        'queue.connections.pricing_fault' => ['driver' => 'redis', 'connection' => 'pricing_fault', 'queue' => 'pricing-fault-test', 'retry_after' => 180],
        'queue.default' => 'pricing_fault']);
    Bus::swap($realBus);
    $id = $service->confirm($admin, $service->preview($type->id, false, '450'));
    $op = DB::table('catalog_price_operations')->find($id);
    check($op->status === 'pending' && $op->error !== null && (int) $op->last_id === 0, 'Redis outage lost operation');
    Bus::fake();
    measure('recover durable operation after actual Redis connection refusal', function () use ($service, $id): void {
        while (! $service->batch($id)) {
        }
    });
    check(DB::table('catalog_price_operations')->find($id)->status === 'done', 'Redis recovery failed');
    $id = $service->confirm($admin, $service->preview($type->id, false, '475'));
    $connectionId = (int) DB::selectOne('select connection_id() as id')->id;
    config(['database.connections.pricing_fault_control' => config('database.connections.mysql')]);
    $cut = false;
    DB::listen(function ($event) use (&$cut, $connectionId): void {
        if (! $cut && $event->connectionName === 'mysql' && str_starts_with($event->sql, 'update `products`')) {
            $cut = true;
            DB::connection('pricing_fault_control')->statement('KILL CONNECTION '.$connectionId);
        }
    });
    try {
        (new ApplyCatalogPrices($id, 0))->handle($service);
        throw new RuntimeException('Expected connection loss');
    } catch (Throwable $error) {
        check($cut, 'Unexpected database failure: '.$error->getMessage());
        echo json_encode(['interrupted_mysql_error' => $error->getMessage()]).PHP_EOL;
    }
    DB::purge('mysql');
    check((int) DB::table('catalog_price_operations')->find($id)->last_id === 0, 'Connection loss committed cursor');
    check(DB::table('products')->where('part_type_id', $type->id)->where('price', 475)->count() === 0, 'Connection loss committed prices');
    measure('recover after actual MySQL connection loss during transaction', function () use ($service, $id): void {
        while (! $service->batch($id)) {
        }
    });
    check(DB::table('catalog_price_operations')->find($id)->status === 'done', 'MySQL recovery failed');
    echo json_encode(['redis_outage' => 'PASS', 'mysql_connection_loss' => 'PASS']).PHP_EOL;
    exit(0);
}
measure('migrate isolated empty MySQL '.$version, fn () => Artisan::call('migrate', ['--force' => true]));
$admin = User::factory()->superAdmin()->create();
$parent = PartType::factory()->create(['base_price' => 100]);
$types = collect(range(1, 10))->map(fn () => PartType::factory()->create(['parent_id' => $parent->id, 'base_price' => 100]));
$sample = Product::factory()->forPartType($types->first())->create(['price' => 100, 'price_mode' => 'unknown']);
$variant = ProductVariant::factory()->create(['product_id' => $sample->id, 'price' => 100, 'price_mode' => 'unknown']);
$productRow = (array) DB::table('products')->find($sample->id);
$variantRow = (array) DB::table('product_variants')->find($variant->id);
DB::table('product_variants')->delete();
DB::table('products')->delete();
measure('seed 20000 products and 500000 variants', function () use ($productRow, $variantRow, $types): void {
    for ($start = 1; $start <= 20000; $start += 100) {
        $products = [];
        $variants = [];
        for ($id = $start; $id < $start + 100; $id++) {
            $mode = match ($id % 10) {
                0 => 'individual', 1, 2, 3, 4, 5 => 'unknown', default => 'inherited'
            };
            $price = $mode === 'individual' || ($mode === 'unknown' && $id % 7 === 0) ? 130 : 100;
            $products[] = array_replace($productRow, ['id' => $id, 'slug' => 'load-'.$id, 'title' => 'Нагрузочный товар '.$id,
                'sku' => null, 'part_type_id' => $types[intdiv($id - 1, 2000)]->id, 'price_mode' => $mode, 'price' => $price,
                'status' => $id % 4 === 0 ? 'archived' : 'active']);
            for ($v = 0; $v < 25; $v++) {
                $variants[] = array_replace($variantRow, ['id' => ($id - 1) * 25 + $v + 1, 'product_id' => $id, 'sku' => null,
                    'price_mode' => $v === 24 && $id % 13 === 0 ? 'individual' : $mode,
                    'price' => $v === 24 && $id % 13 === 0 ? 170 : $price, 'is_default' => $v === 0]);
            }
        }
        DB::table('products')->insert($products);
        foreach (array_chunk($variants, 500) as $chunk) {
            DB::table('product_variants')->insert($chunk);
        }
    }
});
check(DB::table('products')->count() === 20000 && DB::table('product_variants')->count() === 500000, 'Wrong fixture size');
$service = app(CatalogBulkPrices::class);
$type = $types->first();
$preview = measure('historical analysis 2000 products 50000 variants', fn () => $service->preview($type->id, false, '100', action: 'adopt'));
echo json_encode(['historical_counts' => $preview['counts']], JSON_UNESCAPED_UNICODE).PHP_EOL;
$id = measure('confirm adoption with durable fingerprints', fn () => $service->confirm($admin, $preview));
measure('adopt matching historical prices', function () use ($service, $id): void {
    while (! $service->batch($id)) {
    }
});
check(DB::table('catalog_price_operations')->find($id)->status === 'done', 'Adoption did not complete');
$preview = measure('preview concrete type', fn () => $service->preview($type->id, false, '200'));
$id = measure('confirm concrete type', fn () => $service->confirm($admin, $preview));
measure('update concrete type', function () use ($service, $id): void {
    while (! $service->batch($id)) {
    }
});
$ids = $service->scope($parent->id, true);
$query = exceptionPageQuery($service, $ids);
measure('search exceptions first page', fn () => $query->paginate(20));
measure('search exceptions page 100', fn () => $query->paginate(20, ['*'], 'page', 100));
echo json_encode(['exception_plan' => DB::select('EXPLAIN '.$query->limit(20)->toSql(), $query->getBindings())]).PHP_EOL;
$preview = measure('preview parent 20000 products 500000 variants', fn () => $service->preview($parent->id, true, '300'));
$id = measure('confirm parent', fn () => $service->confirm($admin, $preview));
measure('commit first batch before simulated interruption', fn () => $service->batch($id));
check(DB::table('catalog_price_operations')->find($id)->last_id === 100, 'First batch cursor incorrect');
DB::table('catalog_price_operations')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);
$service->dispatchPending();
measure('resume remaining parent batches', function () use ($service, $id): void {
    while (! $service->batch($id)) {
    }
});
$op = DB::table('catalog_price_operations')->find($id);
check($op->status === 'done' && json_decode($op->report, true)['processed'] === 20000, 'Resume failed');
check(DB::table('product_variants')->where('price_mode', 'individual')->where('price', 300)->count() === 0, 'Individual overwritten');
$report = $op->report;
$service->batch($id);
check(DB::table('catalog_price_operations')->find($id)->report === $report, 'Duplicate changed results');
echo json_encode(['parent_report' => json_decode($report, true), 'products' => DB::table('products')->count(),
    'variants' => DB::table('product_variants')->count(), 'indexes' => DB::select('SHOW INDEX FROM product_variants')]).PHP_EOL;
// Kill a real process after a product UPDATE, before its batch transaction commits.
$killPreview = $service->preview($type->id, false, '400');
$killId = $service->confirm($admin, $killPreview);
$beforeKilledPrice = DB::table('products')->where('id', 6)->value('price');
DB::disconnect();
$killSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$worker = pcntl_fork();
if ($worker === 0) {
    fclose($killSockets[0]);
    DB::purge();
    DB::listen(function ($event) use ($killSockets): void {
        if (str_starts_with($event->sql, 'update `products`')) {
            fwrite($killSockets[1], "updated\n");
            sleep(30);
        }
    });
    $service->batch($killId);
    exit(2);
}
fclose($killSockets[1]);
DB::purge();
stream_set_timeout($killSockets[0], 20);
check(trim((string) fgets($killSockets[0])) === 'updated', 'Worker never reached transactional write');
posix_kill($worker, SIGKILL);
pcntl_waitpid($worker, $killedStatus);
fclose($killSockets[0]);
check(pcntl_wifsignaled($killedStatus), 'Worker was not killed');
check(DB::table('catalog_price_operations')->find($killId)->last_id === 0, 'Killed worker committed cursor');
check(DB::table('products')->where('id', 6)->value('price') === $beforeKilledPrice, 'Killed worker committed price');
measure('recover after actual SIGKILL inside batch transaction', function () use ($service, $admin, $killId): void {
    $service->resume($admin, $killId);
    while (! $service->batch($killId)) {
    }
});
check(DB::table('catalog_price_operations')->find($killId)->status === 'done', 'Killed operation did not recover');
echo json_encode(['worker_killed_inside_transaction' => 'PASS', 'rollback_and_resume' => 'PASS']).PHP_EOL;
// Two independent MySQL sessions deliberately invert lock order to exercise actual deadlock retries.
DB::table('catalog_price_locks')->insert(['id' => 2]);
DB::disconnect();
$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$pid = pcntl_fork();
if ($pid === 0) {
    fclose($sockets[0]);
    DB::purge();
    $attempts = 0;
    DB::transaction(function () use (&$attempts, $sockets): void {
        $attempts++;
        DB::table('catalog_price_locks')->where('id', 2)->lockForUpdate()->first();
        if ($attempts === 1) {
            fwrite($sockets[1], "ready\n");
            usleep(300000);
        }
        DB::table('catalog_price_locks')->where('id', 1)->lockForUpdate()->first();
    }, 3);
    fwrite($sockets[1], (string) $attempts);
    fclose($sockets[1]);
    exit(0);
}
fclose($sockets[1]);
DB::purge();
$attempts = 0;
measure('real MySQL deadlock and transaction retry', function () use (&$attempts, $sockets): void {
    DB::transaction(function () use (&$attempts, $sockets): void {
        $attempts++;
        DB::table('catalog_price_locks')->where('id', 1)->lockForUpdate()->first();
        if ($attempts === 1) {
            fgets($sockets[0]);
        }
        DB::table('catalog_price_locks')->where('id', 2)->lockForUpdate()->first();
    }, 3);
});
pcntl_waitpid($pid, $status);
$childAttempts = (int) stream_get_contents($sockets[0]);
fclose($sockets[0]);
check(pcntl_wexitstatus($status) === 0 && $attempts + $childAttempts >= 3, 'Deadlock was not retried');
echo json_encode(['deadlock_parent_attempts' => $attempts, 'deadlock_child_attempts' => $childAttempts, 'result' => 'PASS']).PHP_EOL;
