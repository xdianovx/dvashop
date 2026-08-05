<?php

use App\Models\ShopSetting;
use App\Models\User;
use App\Services\Settings\ShopSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('shop settings service creates exactly one protected singleton', function (): void {
    $service = app(ShopSettingsService::class);
    $first = $service->current();
    $second = $service->current();

    expect($first->is($second))->toBeTrue()
        ->and($first->singleton_key)->toBe(ShopSetting::SINGLETON_KEY)
        ->and($first->store_name)->toBe('AVTOPOROGI.ru')
        ->and(ShopSetting::query()->count())->toBe(1)
        ->and(fn () => ShopSetting::query()->create([
            'singleton_key' => ShopSetting::SINGLETON_KEY,
            'store_name' => 'Вторая запись',
        ]))->toThrow(ValidationException::class, 'второй записи')
        ->and(fn () => ShopSetting::query()->create([
            'singleton_key' => 'other',
            'store_name' => 'Другой ключ',
        ]))->toThrow(ValidationException::class, 'default')
        ->and(fn () => $first->delete())->toThrow(ValidationException::class, 'нельзя удалить')
        ->and(fn () => $first->forceDelete())->toThrow(ValidationException::class, 'безвозвратно');

    expect(ShopSetting::query()->count())->toBe(1);
});

test('shop settings update trims normalizes and persists only allowed fields transactionally', function (): void {
    $service = app(ShopSettingsService::class);
    $admin = User::factory()->admin()->create();

    $setting = $service->update($admin, [
        'store_name' => '  МагазПороги  ',
        'phone_display' => '  +7 (999) 111-22-33  ',
        'phone_href' => ' +7 (999) 111-22-33 ',
        'phone_caption' => '  Звонок бесплатный  ',
        'public_email' => '  SALES@EXAMPLE.RU  ',
        'order_notification_email' => '  ORDERS@EXAMPLE.RU  ',
        'work_hours' => '  Пн–Пт 09:00–18:00  ',
        'legal_name' => '  ООО «МагазПороги»  ',
        'inn' => '1234567890',
        'ogrn' => '1234567890123',
        'legal_address' => '  Москва  ',
        'vk_url' => ' https://vk.com/magazporogi ',
        'telegram_url' => ' https://t.me/magazporogi ',
        'footer_copyright' => '  © МагазПороги  ',
        'footer_disclaimer' => '  Информация не является офертой.  ',
    ]);

    expect($setting->store_name)->toBe('МагазПороги')
        ->and($setting->phone_href)->toBe('+79991112233')
        ->and($setting->public_email)->toBe('sales@example.ru')
        ->and($setting->order_notification_email)->toBe('orders@example.ru')
        ->and($setting->legal_address)->toBe('Москва')
        ->and(ShopSetting::query()->count())->toBe(1);

    expect(fn () => $service->update($admin, [
        'store_name' => 'Не должно сохраниться',
        'forged_admin_field' => 'unsafe',
    ]))->toThrow(ValidationException::class, 'нельзя изменять');

    expect($setting->refresh()->store_name)->toBe('МагазПороги')
        ->and(array_key_exists('forged_admin_field', $setting->getAttributes()))->toBeFalse();
});

test('shop settings reject unsafe and malformed values without partial updates', function (string $field, mixed $value): void {
    $service = app(ShopSettingsService::class);
    $admin = User::factory()->admin()->create();
    $setting = $service->current();
    $original = $setting->getAttributes();

    expect(fn () => $service->update($admin, [$field => $value]))
        ->toThrow(ValidationException::class);

    expect($setting->refresh()->getAttributes())->toMatchArray($original);
})->with([
    'phone javascript' => ['phone_href', 'javascript:alert(1)'],
    'phone too short' => ['phone_href', '+7 (12) 34'],
    'public email' => ['public_email', 'not-an-email'],
    'notification email array' => ['order_notification_email', []],
    'inn length' => ['inn', '12345678901'],
    'ogrn length' => ['ogrn', '12345678901234'],
    'vk javascript' => ['vk_url', 'javascript:alert(1)'],
    'telegram data' => ['telegram_url', 'data:text/plain,test'],
    'protocol relative' => ['vk_url', '//vk.com/test'],
    'file scheme' => ['telegram_url', 'file:///tmp/test'],
    'html store name' => ['store_name', '<b>Магазин</b>'],
    'html footer' => ['footer_copyright', '<script>alert(1)</script>'],
]);

test('shop settings role matrix allows admin updates and manager view only', function (): void {
    $service = app(ShopSettingsService::class);
    $setting = $service->current();
    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive' => User::factory()->admin()->inactive()->create(),
        'blocked' => User::factory()->admin()->blocked()->create(),
    ];

    foreach ($actors as $role => $actor) {
        $mayView = in_array($role, ['super_admin', 'admin', 'manager'], true);
        $mayUpdate = in_array($role, ['super_admin', 'admin'], true);

        expect($actor->can('view', $setting), "{$role}:view")->toBe($mayView)
            ->and($actor->can('update', $setting), "{$role}:update")->toBe($mayUpdate);

        if ($mayUpdate) {
            expect($service->update($actor, ['store_name' => "Магазин {$role}"]))->toBeInstanceOf(ShopSetting::class);
        } else {
            expect(fn () => $service->update($actor, ['store_name' => "Запрещено {$role}"]))
                ->toThrow(AuthorizationException::class);
        }
    }

    $invalidRole = User::factory()->manager()->create();
    DB::table('users')->whereKey($invalidRole)->update(['role' => 'invalid-role']);

    expect(fn () => $service->update($invalidRole->refresh(), ['store_name' => 'Запрещено']))
        ->toThrow(AuthorizationException::class);
});
