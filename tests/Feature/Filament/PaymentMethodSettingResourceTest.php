<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\PaymentMethodSettings\Pages\EditPaymentMethodSetting;
use App\Filament\Resources\PaymentMethodSettings\Pages\ListPaymentMethodSettings;
use App\Filament\Resources\PaymentMethodSettings\PaymentMethodSettingResource;
use App\Models\PaymentMethodSetting;
use App\Models\User;
use App\Policies\PaymentMethodSettingPolicy;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('payment method resource is registered without create or delete operations', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $setting = PaymentMethodSetting::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(PaymentMethodSettingResource::class)
        ->and(PaymentMethodSettingResource::getNavigationGroup())->toBe('Продажи')
        ->and(PaymentMethodSettingResource::getNavigationLabel())->toBe('Способы оплаты')
        ->and(array_keys(PaymentMethodSettingResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(PaymentMethodSetting::class))
        ->toBeInstanceOf(PaymentMethodSettingPolicy::class);

    Livewire::test(ListPaymentMethodSettings::class)
        ->assertCanSeeTableRecords(PaymentMethodSetting::query()->ordered()->get())
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('description')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $setting)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditPaymentMethodSetting::class, ['record' => $setting->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('admin edits toggles and reorders payment methods only through services', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $setting = PaymentMethodSetting::query()->where('code', PaymentMethod::Sbp)->firstOrFail();

    Livewire::test(EditPaymentMethodSetting::class, ['record' => $setting->getKey()])
        ->fillForm([
            'title' => 'Оплата через СБП',
            'description' => 'По QR-коду',
            'position' => 15,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($setting->refresh()->title)->toBe('Оплата через СБП');

    Livewire::test(ListPaymentMethodSettings::class)
        ->callTableAction('toggle_active', $setting)
        ->assertHasNoTableActionErrors();
    expect($setting->refresh()->is_active)->toBeFalse();

    $ids = PaymentMethodSetting::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListPaymentMethodSettings::class)
        ->call('reorderTable', $ids)
        ->assertHasNoErrors();
    expect(PaymentMethodSetting::query()->ordered()->pluck('id')->all())->toBe($ids);
});

test('payment method livewire validation and manager view only access cannot be forged', function (): void {
    $setting = PaymentMethodSetting::query()->where('code', PaymentMethod::Card)->firstOrFail();
    $before = $setting->getAttributes();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditPaymentMethodSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['title' => '<script>bad</script>', 'position' => -1])
        ->call('save')
        ->assertStatus(200)
        ->assertHasFormErrors(['position']);
    expect($setting->fresh()->getAttributes())->toEqual($before);

    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);
    $this->get(PaymentMethodSettingResource::getUrl('index'))->assertOk();
    $this->get(PaymentMethodSettingResource::getUrl('view', ['record' => $setting]))->assertOk();
    expect($this->get(PaymentMethodSettingResource::getUrl('edit', ['record' => $setting]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListPaymentMethodSettings::class)
        ->assertTableActionVisible('view', $setting)
        ->assertTableActionHidden('edit', $setting)
        ->assertTableActionHidden('toggle_active', $setting);
    expect($setting->fresh()->getAttributes())->toEqual($before);
});
