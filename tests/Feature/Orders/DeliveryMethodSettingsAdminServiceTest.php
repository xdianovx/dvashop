<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\DeliveryMethodSettingsAdminService;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);
    $this->deliveryService = app(DeliveryMethodSettingsAdminService::class);
    $this->deliveryAdmin = User::factory()->admin()->create();
});

test('delivery settings update is normalized locked and does not change existing orders', function (): void {
    $setting = DeliveryMethodSetting::query()->where('code', DeliveryMethod::Courier)->firstOrFail();
    $order = Order::factory()->create([
        'delivery_method' => DeliveryMethod::Courier,
        'delivery_price' => 120,
        'total' => 5120,
    ]);

    $updated = $this->deliveryService->update($this->deliveryAdmin, $setting, [
        'code' => DeliveryMethod::Courier->value,
        'title' => '  Доставка курьером  ',
        'description' => '  Только настройка администратора  ',
        'page_title' => '  Заголовок страницы доставки  ',
        'page_description' => '  Полное описание страницы доставки  ',
        'base_price' => '450.50',
        'price_mode' => DeliveryPriceMode::Fixed->value,
        'is_active' => true,
        'position' => 25,
    ]);

    expect($updated->title)->toBe('Доставка курьером')
        ->and($updated->description)->toBe('Только настройка администратора')
        ->and($updated->page_title)->toBe('Заголовок страницы доставки')
        ->and($updated->page_description)->toBe('Полное описание страницы доставки')
        ->and($updated->base_price)->toBe('450.50')
        ->and($updated->price_mode)->toBe(DeliveryPriceMode::Fixed)
        ->and($updated->position)->toBe(25)
        ->and($order->refresh()->delivery_method)->toBe(DeliveryMethod::Courier)
        ->and($order->delivery_price)->toBe('120.00')
        ->and($order->total)->toBe('5120.00');
});

test('delivery settings reject forged fields and invalid values without partial changes', function (array $payload, string $field): void {
    $setting = DeliveryMethodSetting::query()->where('code', DeliveryMethod::Pickup)->firstOrFail();
    $before = $setting->getAttributes();

    try {
        $this->deliveryService->update($this->deliveryAdmin, $setting, $payload);
        test()->fail('ValidationException was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect($setting->fresh()->getAttributes())->toBe($before);
})->with([
    'unknown field' => [['forged' => true], 'forged'],
    'immutable code' => [['code' => DeliveryMethod::Post->value], 'code'],
    'html title' => [['title' => '<b>Самовывоз</b>'], 'title'],
    'html description' => [['description' => '<script>alert(1)</script>'], 'description'],
    'html page title' => [['page_title' => '<style>body{}</style>'], 'page_title'],
    'html page description' => [['page_description' => '<iframe src="x"></iframe>'], 'page_description'],
    'negative price' => [['base_price' => -1], 'base_price'],
    'too precise price' => [['base_price' => '1.999'], 'base_price'],
    'fixed zero price' => [['price_mode' => DeliveryPriceMode::Fixed->value], 'base_price'],
    'free non-zero price' => [['price_mode' => DeliveryPriceMode::Free->value, 'base_price' => 1], 'base_price'],
    'negative position' => [['position' => -1], 'position'],
]);

test('delivery activation and reorder are transactional complete and sequential', function (): void {
    $settings = DeliveryMethodSetting::query()->ordered()->get();
    $target = $settings->first();

    $this->deliveryService->setActive($this->deliveryAdmin, $target, false);
    expect($target->refresh()->is_active)->toBeFalse();

    $reversed = $settings->pluck('id')->reverse()->values()->all();
    $this->deliveryService->reorder($this->deliveryAdmin, $reversed);

    expect(DeliveryMethodSetting::query()->ordered()->pluck('id')->all())->toBe($reversed)
        ->and(DeliveryMethodSetting::query()->ordered()->pluck('position')->all())->toBe([0, 1, 2, 3]);

    $before = DeliveryMethodSetting::query()->orderBy('id')->pluck('position', 'id')->all();

    foreach ([[$reversed[0], $reversed[0]], array_slice($reversed, 0, 3), [...$reversed, 999999]] as $forged) {
        expect(fn () => $this->deliveryService->reorder($this->deliveryAdmin, $forged))
            ->toThrow(ValidationException::class);
        expect(DeliveryMethodSetting::query()->orderBy('id')->pluck('position', 'id')->all())->toBe($before);
    }
});

test('delivery settings policy model guards and direct service authorization are enforced', function (): void {
    $setting = DeliveryMethodSetting::query()->firstOrFail();
    $manager = User::factory()->manager()->create();
    $customer = User::factory()->create();

    expect($manager->can('view', $setting))->toBeTrue()
        ->and($manager->can('update', $setting))->toBeFalse()
        ->and($manager->can('create', DeliveryMethodSetting::class))->toBeFalse()
        ->and($this->deliveryAdmin->can('delete', $setting))->toBeFalse()
        ->and($this->deliveryAdmin->can('forceDelete', $setting))->toBeFalse()
        ->and($this->deliveryAdmin->can('replicate', $setting))->toBeFalse()
        ->and($customer->can('view', $setting))->toBeFalse();

    expect(fn () => $this->deliveryService->update($manager, $setting, ['title' => 'Нельзя']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->deliveryService->setActive($manager, $setting, false))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->deliveryService->reorder($manager, DeliveryMethodSetting::query()->pluck('id')->all()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $setting->delete())->toThrow(ValidationException::class)
        ->and(fn () => $setting->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $setting->replicate())->toThrow(ValidationException::class);

    expect(fn () => $setting->code = 'unknown')->toThrow(ValidationException::class);
});
