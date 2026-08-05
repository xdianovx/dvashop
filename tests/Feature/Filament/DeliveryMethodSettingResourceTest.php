<?php

use App\Enums\DeliveryMethod;
use App\Filament\Resources\DeliveryMethodSettings\DeliveryMethodSettingResource;
use App\Filament\Resources\DeliveryMethodSettings\Pages\EditDeliveryMethodSetting;
use App\Filament\Resources\DeliveryMethodSettings\Pages\ListDeliveryMethodSettings;
use App\Models\DeliveryMethodSetting;
use App\Models\User;
use App\Policies\DeliveryMethodSettingPolicy;
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

test('delivery method resource is registered without create or delete operations', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $setting = DeliveryMethodSetting::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(DeliveryMethodSettingResource::class)
        ->and(DeliveryMethodSettingResource::getNavigationGroup())->toBe('Продажи')
        ->and(DeliveryMethodSettingResource::getNavigationLabel())->toBe('Способы доставки')
        ->and(array_keys(DeliveryMethodSettingResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(DeliveryMethodSetting::class))
        ->toBeInstanceOf(DeliveryMethodSettingPolicy::class);

    Livewire::test(ListDeliveryMethodSettings::class)
        ->assertCanSeeTableRecords(DeliveryMethodSetting::query()->ordered()->get())
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('description')
        ->assertTableColumnExists('base_price')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $setting)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditDeliveryMethodSetting::class, ['record' => $setting->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('admin edits toggles and reorders delivery methods only through services', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $setting = DeliveryMethodSetting::query()->where('code', DeliveryMethod::Courier)->firstOrFail();

    Livewire::test(EditDeliveryMethodSetting::class, ['record' => $setting->getKey()])
        ->fillForm([
            'title' => 'Доставка курьером',
            'description' => 'По согласованию',
            'base_price' => '350.25',
            'position' => 15,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($setting->refresh()->title)->toBe('Доставка курьером')
        ->and($setting->base_price)->toBe('350.25');

    Livewire::test(ListDeliveryMethodSettings::class)
        ->callTableAction('toggle_active', $setting)
        ->assertHasNoTableActionErrors();
    expect($setting->refresh()->is_active)->toBeFalse();

    $ids = DeliveryMethodSetting::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListDeliveryMethodSettings::class)
        ->call('reorderTable', $ids)
        ->assertHasNoErrors();
    expect(DeliveryMethodSetting::query()->ordered()->pluck('id')->all())->toBe($ids);
});

test('delivery method livewire validation and manager view only access cannot be forged', function (): void {
    $setting = DeliveryMethodSetting::query()->where('code', DeliveryMethod::Pickup)->firstOrFail();
    $before = $setting->getAttributes();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditDeliveryMethodSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['title' => '<script>bad</script>', 'base_price' => -1])
        ->call('save')
        ->assertStatus(200)
        ->assertHasFormErrors(['base_price']);
    expect($setting->fresh()->getAttributes())->toEqual($before);

    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);
    $this->get(DeliveryMethodSettingResource::getUrl('index'))->assertOk();
    $this->get(DeliveryMethodSettingResource::getUrl('view', ['record' => $setting]))->assertOk();
    expect($this->get(DeliveryMethodSettingResource::getUrl('edit', ['record' => $setting]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListDeliveryMethodSettings::class)
        ->assertTableActionVisible('view', $setting)
        ->assertTableActionHidden('edit', $setting)
        ->assertTableActionHidden('toggle_active', $setting);
    expect($setting->fresh()->getAttributes())->toEqual($before);
});
