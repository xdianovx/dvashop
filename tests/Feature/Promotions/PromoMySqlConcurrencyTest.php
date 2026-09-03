<?php

use App\Enums\CartStatus;
use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Services\CartManager;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/** @return array<string, mixed> */
function promoMysqlCheckoutData(): array
{
    return [
        'customer_name' => 'Конкурентный покупатель',
        'customer_phone' => '+79990000000',
        'customer_email' => 'concurrency@example.test',
        'customer_city' => 'Москва',
        'customer_address' => 'Улица, 1',
        'customer_comment' => null,
        'delivery_method' => DeliveryMethod::TransportCompany->value,
        'payment_method' => PaymentMethod::Sbp->value,
        'agree_terms' => true,
    ];
}

test('two concurrent mysql checkouts cannot exceed promo usage limit', function (): void {
    $database = (string) getenv('PROMO_MYSQL_DATABASE');

    expect($database)->toMatch('/\Advashop_promo_concurrency_[a-z0-9_]+\z/');

    $connection = 'promo_mysql_concurrency';
    $guardConnection = 'promo_mysql_concurrency_guard';
    $originalConnection = DB::getDefaultConnection();
    $mysqlConfig = [
        ...config('database.connections.mysql'),
        'host' => (string) (getenv('PROMO_MYSQL_HOST') ?: 'mysql'),
        'port' => (string) (getenv('PROMO_MYSQL_PORT') ?: '3306'),
        'database' => $database,
        'username' => (string) getenv('PROMO_MYSQL_USERNAME'),
        'password' => (string) getenv('PROMO_MYSQL_PASSWORD'),
    ];

    config([
        "database.connections.{$connection}" => $mysqlConfig,
        "database.connections.{$guardConnection}" => $mysqlConfig,
    ]);
    DB::purge($connection);
    DB::purge($guardConnection);
    DB::setDefaultConnection($connection);

    try {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        DeliveryMethodSetting::factory()->create([
            'code' => DeliveryMethod::TransportCompany,
            'base_price' => 250,
            'price_mode' => DeliveryPriceMode::Fixed,
            'is_active' => true,
        ]);
        PaymentMethodSetting::factory()->create([
            'code' => PaymentMethod::Sbp,
            'is_active' => true,
        ]);
        $promo = PromoCode::factory()->create([
            'code' => 'MYSQL-RACE-ONE',
            'discount_value' => 10,
            'usage_limit' => 1,
        ]);
        $variant = ProductVariant::factory()->default()->create([
            'price' => 1000,
            'stock_quantity' => 10,
        ]);
        $carts = Cart::factory()->count(2)->create(['promo_code_id' => $promo->getKey()]);

        foreach ($carts as $cart) {
            CartItem::query()->create([
                'cart_id' => $cart->getKey(),
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
                'sku_snapshot' => $variant->sku ?: $variant->product->sku,
                'price_snapshot' => 1000,
                'old_price_snapshot' => null,
                'title_snapshot' => $variant->product->title,
                'options_snapshot' => $variant->options,
                'image_snapshot' => '/img/placeholders/image.svg',
            ]);
        }

        DB::disconnect($connection);
        $guard = DB::connection($guardConnection);
        $guard->beginTransaction();
        $guard->table('promo_codes')->where('id', $promo->getKey())->lockForUpdate()->first();
        $workers = [];

        foreach ($carts as $cart) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            expect($sockets)->not->toBeFalse();
            [$parentSocket, $childSocket] = $sockets;
            $pid = pcntl_fork();
            expect($pid)->not->toBe(-1);

            if ($pid === 0) {
                fclose($parentSocket);
                DB::purge($connection);
                DB::setDefaultConnection($connection);
                Event::fake([OrderCreated::class]);
                fwrite($childSocket, 'R');
                fflush($childSocket);
                fread($childSocket, 1);

                try {
                    $request = Request::create(
                        '/checkout',
                        'POST',
                        [],
                        [CartManager::COOKIE_NAME => $cart->token],
                    );
                    $order = app(CheckoutService::class)->createOrderFromCart($request, promoMysqlCheckoutData());
                    $result = ['status' => 'success', 'order_id' => $order->getKey()];
                } catch (ValidationException $exception) {
                    $result = ['status' => 'rejected', 'message' => $exception->getMessage()];
                } catch (Throwable $exception) {
                    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
                }

                fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR));
                fclose($childSocket);
                exit(0);
            }

            fclose($childSocket);
            $workers[] = ['pid' => $pid, 'socket' => $parentSocket];
        }

        foreach ($workers as $worker) {
            expect(fread($worker['socket'], 1))->toBe('R');
        }
        foreach ($workers as $worker) {
            fwrite($worker['socket'], 'G');
            fflush($worker['socket']);
        }

        usleep(100_000);
        $guard->commit();
        $results = [];

        foreach ($workers as $worker) {
            $results[] = json_decode(stream_get_contents($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        DB::purge($connection);
        DB::setDefaultConnection($connection);
        $statuses = collect($results)->pluck('status')->sort()->values()->all();

        expect($statuses)->toBe(['rejected', 'success'])
            ->and(Order::query()->count())->toBe(1)
            ->and(PromoCodeRedemption::query()->whereNull('released_at')->count())->toBe(1)
            ->and($variant->refresh()->stock_quantity)->toBe(9)
            ->and(Cart::query()->where('status', CartStatus::Ordered)->count())->toBe(1);
    } finally {
        if (isset($guard) && $guard->transactionLevel() > 0) {
            $guard->rollBack();
        }

        DB::disconnect($connection);
        DB::disconnect($guardConnection);
        DB::setDefaultConnection($originalConnection);
        config([
            "database.connections.{$connection}" => null,
            "database.connections.{$guardConnection}" => null,
        ]);
    }
})->skip(
    fn (): bool => getenv('PROMO_MYSQL_CONCURRENCY') !== '1',
    'Requires an explicitly provisioned isolated MySQL schema.',
);
