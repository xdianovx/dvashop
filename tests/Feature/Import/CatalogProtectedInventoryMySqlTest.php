<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\ImportRun;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CartManager;
use App\Services\Catalog\CatalogImportDatabaseGuards;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\CheckoutService;
use App\Services\Import\CatalogSafePartialState;
use App\Services\Orders\OrderOperationsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires separately isolated MySQL.');
    }
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDatabaseName())->toBe('dvashop_guard_test');
    expect(config('database.connections.mysql.host'))->toStartWith('dvashop_safe_partial_mysql_');
    // Keep committed fixtures for separate-process races. Every test creates
    // distinct catalog rows; permanent protections are never removed for cleanup.
    if (! ($GLOBALS['catalog_inventory_mysql_schema_ready'] ?? false)) {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $GLOBALS['catalog_inventory_mysql_schema_ready'] = true;
    }
    DB::table('catalog_price_locks')->where('id', 1)->update(['safe_partial_run_id' => null]);
    Bus::fake();
    Event::fake([OrderCreated::class]);
    DeliveryMethodSetting::query()->firstOrCreate(['code' => DeliveryMethod::TransportCompany], ['title' => 'Inventory delivery',
        'price_mode' => DeliveryPriceMode::Fixed, 'base_price' => 700, 'is_active' => true]);
    PaymentMethodSetting::query()->firstOrCreate(['code' => PaymentMethod::Sbp], ['title' => 'Inventory payment', 'is_active' => true]);
});

function inventoryRequest(Cart $cart): Request
{
    $request = Request::create('/checkout', 'POST', [], [CartManager::COOKIE_NAME => $cart->token]);
    $request->setUserResolver(fn () => null);

    return $request;
}

function inventoryCheckout(Cart $cart): Order
{
    return app(CheckoutService::class)->createOrderFromCart(inventoryRequest($cart), [
        'customer_name' => 'Inventory test', 'customer_phone' => '+79991234567', 'customer_city' => 'Москва',
        'delivery_method' => DeliveryMethod::TransportCompany->value, 'payment_method' => PaymentMethod::Sbp->value, 'agree_terms' => true,
    ]);
}

function inventoryFixture(?int $quantity, bool $protected, bool $reserved): array
{
    $variant = ProductVariant::factory()->create(['title' => 'Inventory option', 'stock_quantity' => 3, 'stock_status' => 'in_stock', 'is_active' => true]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(inventoryRequest($cart), $variant->id, 1);
    $variant->update(['stock_quantity' => $quantity]);
    $run = ImportRun::factory()->create(['import_mode' => 'safe_partial']);
    if ($protected) {
        DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$variant->product_id => 'inventory test'], 2, str_repeat('a', 64)));
    }
    if ($reserved) {
        DB::table('catalog_price_locks')->where('id', 1)->update(['safe_partial_run_id' => $run->id]);
    }

    return [$variant->fresh(), $cart, $run];
}

test('protected and ordinary checkout and repeated cancellation preserve inventory semantics', function (?int $stock, bool $protected, bool $reserved): void {
    [$variant, $cart] = inventoryFixture($stock, $protected, $reserved);
    $ordersBefore = Order::count();
    $fingerprint = CatalogSafePartialState::product($variant->product->fresh());
    $protection = DB::table('catalog_import_protections')->get()->toJson();
    if ($stock === 0) {
        expect(fn () => inventoryCheckout($cart))->toThrow(ValidationException::class);
        expect(Order::count())->toBe($ordersBefore);
    } else {
        $order = inventoryCheckout($cart);
        expect($variant->fresh()->stock_quantity)->toBe($stock === null ? null : $stock - 1);
        $actor = User::factory()->superAdmin()->create();
        $operations = app(OrderOperationsService::class);
        $operations->update($actor, $order, ['status' => 'canceled']);
        $operations->update($actor, $order->fresh(), ['status' => 'canceled']);
        expect($order->items()->firstOrFail()->stock_was_decremented)->toBe($stock !== null);
    }
    expect($variant->fresh()->stock_quantity)->toBe($stock)
        ->and(CatalogSafePartialState::product($variant->product->fresh()))->toBe($fingerprint)
        ->and(DB::table('catalog_import_protections')->get()->toJson())->toBe($protection);
})->with([
    [null, true, false], [0, true, false], [3, true, false],
    [null, false, false], [0, false, false], [3, false, false],
    [null, true, true], [0, true, true], [3, true, true],
    [null, false, true], [0, false, true], [3, false, true],
]);

test('inventory bypass keeps all semantic fields and mixed writes guarded with byte exact comparison', function (): void {
    [$variant] = inventoryFixture(3, true, false);
    foreach (['price' => '99999.00', 'stock_status' => 'pre_order', 'is_active' => false,
        'sku' => 'changed', 'title' => strtoupper($variant->title ?: 'changed'), 'product_id' => $variant->product_id + 1,
        'options' => '{"x":"y"}'] as $field => $value) {
        expect(fn () => DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => 2, $field => $value]))
            ->toThrow(QueryException::class, 'catalog import protection');
    }
    expect(fn () => DB::table('product_variants')->where('id', $variant->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('catalog_import_protections')->delete())->toThrow(QueryException::class);
    expect($variant->fresh()->stock_quantity)->toBe(3);
});

test('inventory of another variant does not wait for catalog mutex or protected row locks', function (): void {
    [$protected] = inventoryFixture(3, true, false);
    [$ordinary] = inventoryFixture(3, false, false);
    [$anotherProtected] = inventoryFixture(3, true, false);
    $c = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET innodb_lock_wait_timeout=1');
    DB::beginTransaction();
    try {
        app(CatalogPriceGuard::class)->lock();
        DB::table('catalog_import_protections')->lockForUpdate()->get();
        DB::table('product_variants')->where('id', $protected->id)->lockForUpdate()->first();
        foreach ([$ordinary, $anotherProtected] as $target) {
            $start = hrtime(true);
            expect($other->exec('UPDATE product_variants SET stock_quantity=stock_quantity-1 WHERE id='.$target->id))->toBe(1);
            expect((hrtime(true) - $start) / 1e9)->toBeLessThan(1);
        }
    } finally {
        DB::rollBack();
    }
    expect($ordinary->fresh()->stock_quantity)->toBe(2)
        ->and($anotherProtected->fresh()->stock_quantity)->toBe(2);
});

/** Run two actual service calls while both wait on the same committed row. */
function inventoryRace(string $action, array $ids, int $lockId, ?int $actor = null): array
{
    $directory = sys_get_temp_dir().'/inventory-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $c = DB::connection()->getConfig();
    $settings = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_CONFIG_CACHE' => $directory.'/no-config.php',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $c['host'], 'DB_DATABASE' => $c['database'], 'DB_PORT' => (string) $c['port'],
        'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'DB_URL' => '', 'DB_SOCKET' => '',
        'SCOUT_DRIVER' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        'SHOP_ORDERS_BITRIX_ENABLED' => 'false', 'YANDEX_FEED_ENABLED' => 'false'];
    file_put_contents($directory.'/env.json', json_encode($settings, JSON_THROW_ON_ERROR));
    chmod($directory.'/env.json', 0600);
    DB::beginTransaction();
    $processes = [];
    try {
        DB::table($action === 'checkout' ? 'product_variants' : 'orders')->where('id', $lockId)->lockForUpdate()->first();
        foreach ($ids as $i => $id) {
            $input = ['action' => $action, 'id' => $id, 'actor' => $actor, 'ready' => "$directory/ready$i", 'result' => "$directory/result$i"];
            $processes[] = proc_open([PHP_BINARY, base_path('tests/Support/CatalogInventoryWorker.php'), $directory.'/env.json', json_encode($input)],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$directory/out$i", 'w'], 2 => ['file', "$directory/err$i", 'w']], $pipes, base_path());
        }
        $deadline = microtime(true) + 15;
        do {
            $waiting = (int) DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits')->n;
            if ($waiting >= 2) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect($waiting)->toBeGreaterThanOrEqual(2);
        DB::commit();
        $results = [];
        foreach ($processes as $i => $process) {
            expect(proc_close($process))->toBe(0, (string) file_get_contents("$directory/err$i"));
            $results[] = json_decode(file_get_contents("$directory/result$i"), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        if (DB::transactionLevel()) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('concurrent checkout cannot sell the last protected or ordinary unit twice', function (bool $protected): void {
    [$variant, $first] = inventoryFixture(1, $protected, true);
    $ordersBefore = Order::count();
    $second = Cart::factory()->create();
    app(CartManager::class)->addItem(inventoryRequest($second), $variant->id, 1);
    $results = inventoryRace('checkout', [$first->id, $second->id], $variant->id);
    expect(array_column($results, 'result'))->toContain('ok', 'unavailable');
    expect($variant->fresh()->stock_quantity)->toBe(0)->and(Order::count())->toBe($ordersBefore + 1);
})->with([true, false]);

test('concurrent cancellation restores a protected finite remainder only once', function (): void {
    [$variant, $cart] = inventoryFixture(3, true, true);
    $order = inventoryCheckout($cart);
    $actor = User::factory()->superAdmin()->create();
    $results = inventoryRace('cancel', [$order->id, $order->id], $order->id, $actor->id);
    expect(array_column($results, 'result'))->toBe(['ok', 'ok']);
    expect($variant->fresh()->stock_quantity)->toBe(3)
        ->and($order->items()->whereNotNull('stock_restored_at')->count())->toBe(1);
});

test('inventory guard upgrade retains 54 guards and preserves protections', function (): void {
    [$variant] = inventoryFixture(3, true, false);
    $prefix = 'cip_'.substr(hash('sha256', 'product_variants'), 0, 12).'_update';
    // Reproduce a deployed v1 guard, then exercise the additive upgrade.
    DB::unprepared("CREATE TRIGGER $prefix BEFORE UPDATE ON product_variants FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='legacy guard'");
    DB::unprepared("DROP TRIGGER {$prefix}_inventory_v2");
    app(CatalogImportDatabaseGuards::class)->upgradeInventoryGuard();
    app(CatalogImportDatabaseGuards::class)->upgradeInventoryGuard();
    expect(DB::table('information_schema.triggers')->where('trigger_schema', DB::connection()->getDatabaseName())->count())->toBe(54);
    expect(DB::table('catalog_import_protections')->where('product_id', $variant->product_id)->exists())->toBeTrue();
    $variant->decrement('stock_quantity');
    expect($variant->fresh()->stock_quantity)->toBe(2);
    expect(fn () => $variant->fresh()->update(['price' => 1]))->toThrow(QueryException::class);
});
