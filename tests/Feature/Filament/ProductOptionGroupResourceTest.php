<?php

use App\Filament\Resources\ProductOptionGroups\Pages\CreateProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\Pages\EditProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\Pages\ListProductOptionGroups;
use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Filament\Resources\ProductOptionGroups\RelationManagers\ValuesRelationManager;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\User;
use App\Policies\ProductOptionGroupPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('ProductOptionGroupResource is registered with catalogue labels and columns', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(Filament::getPanel('admin')->getResources())->toContain(ProductOptionGroupResource::class)
        ->and(ProductOptionGroupResource::getNavigationGroup())->toBe('Каталог')
        ->and(ProductOptionGroupResource::getNavigationLabel())->toBe('Группы опций')
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(ProductOptionGroup::class))
        ->toBeInstanceOf(ProductOptionGroupPolicy::class);

    Livewire::test(ListProductOptionGroups::class)
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('slug')
        ->assertTableColumnExists('values_count')
        ->assertTableColumnExists('active_values_count')
        ->assertTableColumnExists('template_items_count')
        ->assertTableColumnExists('is_active');
});

test('admin creates and updates a group through the domain backed resource pages', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateProductOptionGroup::class)
        ->fillForm([
            'title' => 'Комплектация',
            'slug' => 'equipment',
            'code' => 'equipment',
            'input_type' => 'select',
            'applies_to' => ProductOptionGroup::APPLIES_ALL,
            'is_required' => false,
            'is_active' => true,
            'position' => 50,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $group = ProductOptionGroup::query()->where('slug', 'equipment')->firstOrFail();

    Livewire::test(EditProductOptionGroup::class, ['record' => $group->getKey()])
        ->fillForm(['title' => 'Новая комплектация', 'position' => 60])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($group->refresh()->title)->toBe('Новая комплектация')
        ->and($group->position)->toBe(60);
});

test('admin manages values inside the group relation manager', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $group = ProductOptionGroup::factory()->create();

    Livewire::test(ValuesRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditProductOptionGroup::class,
    ])
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('template_items_count')
        ->assertTableColumnExists('variant_option_values_count')
        ->callTableAction('create', data: [
            'title' => 'Премиум',
            'slug' => 'premium',
            'code' => 'premium',
            'position' => 10,
            'is_default' => true,
            'is_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $value = $group->values()->where('slug', 'premium')->firstOrFail();

    Livewire::test(ValuesRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditProductOptionGroup::class,
    ])
        ->callTableAction('edit', $value, data: ['title' => 'Премиум +'])
        ->assertHasNoTableActionErrors();

    expect($value->refresh()->title)->toBe('Премиум +');
});

test('manager has view only group access and destructive operations are denied to everyone', function (): void {
    $group = ProductOptionGroup::factory()->create();
    $manager = User::factory()->manager()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($manager);

    $this->get(ProductOptionGroupResource::getUrl('index'))->assertOk();
    $this->get(ProductOptionGroupResource::getUrl('view', ['record' => $group]))->assertOk();
    expect($this->get(ProductOptionGroupResource::getUrl('create'))->getStatusCode())->not->toBe(200)
        ->and($this->get(ProductOptionGroupResource::getUrl('edit', ['record' => $group]))->getStatusCode())->not->toBe(200)
        ->and($manager->can('update', $group))->toBeFalse()
        ->and($manager->can('delete', $group))->toBeFalse()
        ->and($admin->can('delete', $group))->toBeFalse()
        ->and($admin->can('restore', $group))->toBeFalse()
        ->and($admin->can('forceDelete', $group))->toBeFalse()
        ->and($admin->can('replicate', $group))->toBeFalse();

    Livewire::test(ListProductOptionGroups::class)
        ->assertTableActionVisible('view', $group)
        ->assertTableActionHidden('edit', $group)
        ->assertTableActionHidden('toggle_active', $group)
        ->assertTableActionDoesNotExist('delete');
});

test('group table and deactivation modal count distinct templates without N plus one rows', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $group = ProductOptionGroup::factory()->create();
    $values = ProductOptionValue::factory()->forGroup($group)->count(4)->create();
    $templates = ProductOptionTemplate::factory()->count(2)->create();

    foreach ($templates as $template) {
        foreach ($values as $position => $value) {
            $template->items()->create([
                'product_option_group_id' => $group->getKey(),
                'product_option_value_id' => $value->getKey(),
                'position' => $position,
            ]);
        }
    }

    Livewire::test(ListProductOptionGroups::class)
        ->assertTableColumnStateSet('template_items_count', 2, $group);

    $source = file_get_contents(app_path('Filament/Resources/ProductOptionGroups/ProductOptionGroupResource.php'));

    expect($source)
        ->toContain('$record->template_items_count')
        ->toContain("distinct()->count('product_option_template_id')");
});
