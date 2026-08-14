<?php

use App\Filament\Pages\ShopSettingsPage;
use App\Models\ShopSetting;
use App\Models\User;
use App\Policies\ShopSettingPolicy;
use App\Services\Settings\ShopSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('shop settings page is a registered singleton page backed by its policy', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(Filament::getPanel('admin')->getPages())->toContain(ShopSettingsPage::class)
        ->and(ShopSettingsPage::getNavigationGroup())->toBe('Настройки')
        ->and(ShopSettingsPage::getNavigationLabel())->toBe('Настройки магазина')
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(ShopSetting::class))
        ->toBeInstanceOf(ShopSettingPolicy::class);

    Livewire::test(ShopSettingsPage::class)
        ->assertFormFieldExists('store_name')
        ->assertFormFieldExists('phone_href')
        ->assertFormFieldExists('public_email')
        ->assertFormFieldExists('inquiry_notification_email')
        ->assertFormFieldExists('inn')
        ->assertFormFieldExists('vk_url')
        ->assertFormFieldExists('footer_copyright');

    expect(ShopSetting::query()->count())->toBe(1);
});

test('admin and super admin save singleton settings idempotently with a russian notification', function (string $role): void {
    $actor = $role === 'super_admin'
        ? User::factory()->superAdmin()->create()
        : User::factory()->admin()->create();
    $this->actingAs($actor);

    $component = Livewire::test(ShopSettingsPage::class)
        ->fillForm([
            'store_name' => 'МагазПороги',
            'phone_display' => '+7 (999) 111-22-33',
            'phone_href' => '+7 (999) 111-22-33',
            'public_email' => 'SALES@EXAMPLE.RU',
            'inquiry_notification_email' => 'INQUIRIES@EXAMPLE.RU',
            'inn' => '1234567890',
            'ogrn' => '1234567890123',
            'vk_url' => 'https://vk.com/magazporogi',
            'telegram_url' => 'https://t.me/magazporogi',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Настройки магазина сохранены');

    $component->call('save')->assertHasNoFormErrors();

    $setting = ShopSetting::query()->sole();
    expect($setting->store_name)->toBe('МагазПороги')
        ->and($setting->phone_href)->toBe('+79991112233')
        ->and($setting->public_email)->toBe('sales@example.ru')
        ->and($setting->inquiry_notification_email)->toBe('inquiries@example.ru')
        ->and(ShopSetting::query()->count())->toBe(1);
})->with(['super admin' => ['super_admin'], 'admin' => ['admin']]);

test('manager sees settings but forged save is forbidden and customers or disabled users cannot open the page', function (): void {
    $setting = app(ShopSettingsService::class)->current();
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $this->get(ShopSettingsPage::getUrl())->assertOk();

    Livewire::test(ShopSettingsPage::class)
        ->set('data.store_name', 'Менеджер не должен сохранить')
        ->call('save')
        ->assertForbidden();

    expect($setting->refresh()->store_name)->toBe('AVTOPOROGI.ru');

    foreach ([
        User::factory()->create(),
        User::factory()->admin()->inactive()->create(),
        User::factory()->admin()->blocked()->create(),
    ] as $forbidden) {
        $response = $this->actingAs($forbidden)->get(ShopSettingsPage::getUrl());
        expect($response->getStatusCode())->not->toBe(200)
            ->and($response->getStatusCode())->not->toBe(500);
    }
});

test('forged shop settings livewire state returns field validation without changing the singleton', function (string $field, mixed $value): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $setting = app(ShopSettingsService::class)->current();
    $original = $setting->getAttributes();

    Livewire::test(ShopSettingsPage::class)
        ->set("data.{$field}", $value)
        ->call('save')
        ->assertStatus(200)
        ->assertHasErrors(["data.{$field}"]);

    expect($setting->refresh()->getAttributes())->toMatchArray($original);
})->with([
    'phone array' => ['phone_href', []],
    'phone javascript' => ['phone_href', 'javascript:alert(1)'],
    'invalid email' => ['public_email', 'not-an-email'],
    'invalid inquiry email' => ['inquiry_notification_email', 'not-an-email'],
    'invalid inn' => ['inn', '123'],
    'invalid ogrn' => ['ogrn', '123'],
    'unsafe vk' => ['vk_url', 'data:text/plain,test'],
    'unsafe telegram' => ['telegram_url', '//t.me/test'],
    'html footer' => ['footer_copyright', '<b>unsafe</b>'],
]);
