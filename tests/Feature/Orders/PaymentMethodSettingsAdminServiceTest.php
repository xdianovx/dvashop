<?php

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\User;
use App\Services\Orders\PaymentMethodSettingsAdminService;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);
    $this->paymentService = app(PaymentMethodSettingsAdminService::class);
    $this->paymentAdmin = User::factory()->admin()->create();
});

test('payment settings update is normalized locked and does not change existing orders', function (): void {
    $setting = PaymentMethodSetting::query()->where('code', PaymentMethod::Sbp)->firstOrFail();
    $order = Order::factory()->create([
        'payment_method' => PaymentMethod::Sbp,
        'subtotal' => 4300,
        'total' => 4300,
    ]);

    $updated = $this->paymentService->update($this->paymentAdmin, $setting, [
        'code' => PaymentMethod::Sbp->value,
        'title' => '  Оплата через СБП  ',
        'description' => '  Ручная настройка  ',
        'page_title' => '  Заголовок страницы оплаты  ',
        'page_description' => '  Полное описание страницы оплаты  ',
        'is_active' => true,
        'position' => 15,
    ]);

    expect($updated->title)->toBe('Оплата через СБП')
        ->and($updated->description)->toBe('Ручная настройка')
        ->and($updated->page_title)->toBe('Заголовок страницы оплаты')
        ->and($updated->page_description)->toBe('Полное описание страницы оплаты')
        ->and($updated->position)->toBe(15)
        ->and($order->refresh()->payment_method)->toBe(PaymentMethod::Sbp)
        ->and($order->subtotal)->toBe('4300.00')
        ->and($order->total)->toBe('4300.00');
});

test('payment settings reject forged fields and invalid values without partial changes', function (array $payload, string $field): void {
    $setting = PaymentMethodSetting::query()->where('code', PaymentMethod::Card)->firstOrFail();
    $before = $setting->getAttributes();

    try {
        $this->paymentService->update($this->paymentAdmin, $setting, $payload);
        test()->fail('ValidationException was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect($setting->fresh()->getAttributes())->toBe($before);
})->with([
    'unknown field' => [['forged' => true], 'forged'],
    'immutable code' => [['code' => PaymentMethod::Invoice->value], 'code'],
    'html title' => [['title' => '<b>Карта</b>'], 'title'],
    'html description' => [['description' => '<script>alert(1)</script>'], 'description'],
    'html page title' => [['page_title' => '<style>body{}</style>'], 'page_title'],
    'html page description' => [['page_description' => '<iframe src="x"></iframe>'], 'page_description'],
    'negative position' => [['position' => -1], 'position'],
]);

test('payment activation and reorder are transactional complete and sequential', function (): void {
    $settings = PaymentMethodSetting::query()->ordered()->get();
    $target = $settings->first();

    $this->paymentService->setActive($this->paymentAdmin, $target, false);
    expect($target->refresh()->is_active)->toBeFalse();

    $reversed = $settings->pluck('id')->reverse()->values()->all();
    $this->paymentService->reorder($this->paymentAdmin, $reversed);

    expect(PaymentMethodSetting::query()->ordered()->pluck('id')->all())->toBe($reversed)
        ->and(PaymentMethodSetting::query()->ordered()->pluck('position')->all())->toBe([0, 1, 2, 3]);

    $before = PaymentMethodSetting::query()->orderBy('id')->pluck('position', 'id')->all();

    foreach ([[$reversed[0], $reversed[0]], array_slice($reversed, 0, 3), [...$reversed, 999999]] as $forged) {
        expect(fn () => $this->paymentService->reorder($this->paymentAdmin, $forged))
            ->toThrow(ValidationException::class);
        expect(PaymentMethodSetting::query()->orderBy('id')->pluck('position', 'id')->all())->toBe($before);
    }
});

test('payment settings policy model guards and direct service authorization are enforced', function (): void {
    $setting = PaymentMethodSetting::query()->firstOrFail();
    $manager = User::factory()->manager()->create();
    $customer = User::factory()->create();

    expect($manager->can('view', $setting))->toBeTrue()
        ->and($manager->can('update', $setting))->toBeFalse()
        ->and($manager->can('create', PaymentMethodSetting::class))->toBeFalse()
        ->and($this->paymentAdmin->can('delete', $setting))->toBeFalse()
        ->and($this->paymentAdmin->can('forceDelete', $setting))->toBeFalse()
        ->and($this->paymentAdmin->can('replicate', $setting))->toBeFalse()
        ->and($customer->can('view', $setting))->toBeFalse();

    expect(fn () => $this->paymentService->update($manager, $setting, ['title' => 'Нельзя']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->paymentService->setActive($manager, $setting, false))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->paymentService->reorder($manager, PaymentMethodSetting::query()->pluck('id')->all()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $setting->delete())->toThrow(ValidationException::class)
        ->and(fn () => $setting->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $setting->replicate())->toThrow(ValidationException::class);

    expect(fn () => $setting->code = 'unknown')->toThrow(ValidationException::class);
});
