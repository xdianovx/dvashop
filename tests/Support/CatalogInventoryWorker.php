<?php

use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\CartManager;
use App\Services\CheckoutService;
use App\Services\Orders\OrderOperationsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// Separate process: real MySQL transactions, no inherited PHPUnit connection.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$settings = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
foreach ($settings as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'dvashop_guard_test'
    || ! str_starts_with(config('database.connections.mysql.host'), 'dvashop_safe_partial_mysql_')) {
    throw new RuntimeException('Isolated inventory worker only');
}
Event::fake([OrderCreated::class]);
Http::preventStrayRequests();
Bus::fake();
$input = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
file_put_contents($input['ready'], 'ready');
try {
    if ($input['action'] === 'checkout') {
        $cart = Cart::findOrFail($input['id']);
        $request = Request::create('/checkout', 'POST', [], [CartManager::COOKIE_NAME => $cart->token]);
        $request->setUserResolver(fn () => null);
        $order = app(CheckoutService::class)->createOrderFromCart($request, [
            'customer_name' => 'Inventory test', 'customer_phone' => '+79991234567', 'customer_city' => 'Москва',
            'delivery_method' => 'transport_company', 'payment_method' => 'sbp', 'agree_terms' => true,
        ]);
        $result = ['result' => 'ok', 'id' => $order->id];
    } else {
        app(OrderOperationsService::class)->update(
            User::findOrFail($input['actor']), Order::findOrFail($input['id']), ['status' => 'canceled']);
        $result = ['result' => 'ok'];
    }
} catch (ValidationException $e) {
    $result = ['result' => 'unavailable', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    $result = ['result' => 'error', 'message' => $e->getMessage()];
}
file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR));
