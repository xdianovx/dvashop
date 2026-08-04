<?php

use App\Filament\Resources\ProductOptionTemplates\Pages\CreateProductOptionTemplate;
use App\Filament\Resources\ProductOptionTemplates\Pages\EditProductOptionTemplate;
use App\Filament\Resources\ProductOptionTemplates\Pages\ListProductOptionTemplates;
use App\Filament\Resources\ProductOptionTemplates\ProductOptionTemplateResource;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\User;
use App\Policies\ProductOptionTemplatePolicy;
use App\Services\Catalog\ProductOptionCombinationCalculator;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('ProductOptionTemplateResource is registered and exposes usage and combination columns', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(Filament::getPanel('admin')->getResources())->toContain(ProductOptionTemplateResource::class)
        ->and(ProductOptionTemplateResource::getNavigationLabel())->toBe('Шаблоны опций')
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(ProductOptionTemplate::class))
        ->toBeInstanceOf(ProductOptionTemplatePolicy::class);

    Livewire::test(ListProductOptionTemplates::class)
        ->assertTableColumnExists('groups_count')
        ->assertTableColumnExists('items_count')
        ->assertTableColumnExists('combination_count')
        ->assertTableColumnExists('products_count')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at');
});

test('admin creates and transactionally updates a normalized option template', function (): void {
    $undoRepeaterFake = Repeater::fake();
    $this->actingAs(User::factory()->admin()->create());
    $group = ProductOptionGroup::factory()->create(['applies_to' => ProductOptionGroup::APPLIES_ALL]);
    $first = ProductOptionValue::factory()->forGroup($group)->create();
    $second = ProductOptionValue::factory()->forGroup($group)->create();

    try {
        Livewire::test(CreateProductOptionTemplate::class)
            ->fillForm([
                'title' => 'Шаблон комплектации',
                'slug' => 'equipment-template',
                'applies_to' => ProductOptionGroup::APPLIES_ALL,
                'is_default' => false,
                'is_active' => true,
                'position' => 10,
                'template_items' => [[
                    'product_option_group_id' => $group->getKey(),
                    'product_option_value_id' => $first->getKey(),
                    'position' => 10,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = ProductOptionTemplate::query()->where('slug', 'equipment-template')->firstOrFail();

        Livewire::test(EditProductOptionTemplate::class, ['record' => $template->getKey()])
            ->fillForm([
                'title' => 'Обновлённый шаблон',
                'template_items' => [[
                    'product_option_group_id' => $group->getKey(),
                    'product_option_value_id' => $first->getKey(),
                    'position' => 10,
                ], [
                    'product_option_group_id' => $group->getKey(),
                    'product_option_value_id' => $second->getKey(),
                    'position' => 20,
                ]],
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($template->refresh()->title)->toBe('Обновлённый шаблон')
        ->and($template->items()->count())->toBe(2);
});

test('manager may only list and view templates and no destructive actions exist', function (): void {
    $template = ProductOptionTemplate::factory()->create();
    $manager = User::factory()->manager()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($manager);

    $this->get(ProductOptionTemplateResource::getUrl('index'))->assertOk();
    $this->get(ProductOptionTemplateResource::getUrl('view', ['record' => $template]))->assertOk();
    expect($this->get(ProductOptionTemplateResource::getUrl('create'))->getStatusCode())->not->toBe(200)
        ->and($this->get(ProductOptionTemplateResource::getUrl('edit', ['record' => $template]))->getStatusCode())->not->toBe(200)
        ->and($manager->can('update', $template))->toBeFalse()
        ->and($admin->can('delete', $template))->toBeFalse()
        ->and($admin->can('restore', $template))->toBeFalse()
        ->and($admin->can('forceDelete', $template))->toBeFalse();

    Livewire::test(ListProductOptionTemplates::class)
        ->assertTableActionVisible('view', $template)
        ->assertTableActionHidden('edit', $template)
        ->assertTableActionHidden('toggle_active', $template)
        ->assertTableActionDoesNotExist('delete');
});

test('inactive current group and value remain available with an explicit label', function (): void {
    $group = ProductOptionGroup::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_ALL,
        'is_active' => false,
    ]);
    $value = ProductOptionValue::factory()->forGroup($group)->create(['is_active' => false]);

    expect(ProductOptionTemplateResource::groupOptions(ProductOptionGroup::APPLIES_ALL))
        ->not->toHaveKey($group->getKey())
        ->and(ProductOptionTemplateResource::groupOptions(ProductOptionGroup::APPLIES_ALL, $group->getKey()))
        ->toHaveKey($group->getKey(), $group->title.' (Неактивно)')
        ->and(ProductOptionTemplateResource::valueOptions($group->getKey()))
        ->not->toHaveKey($value->getKey())
        ->and(ProductOptionTemplateResource::valueOptions($group->getKey(), $value->getKey()))
        ->toHaveKey($value->getKey(), $value->title.' (Неактивно)');
});

test('combination counts use resource eager loads without additional queries', function (): void {
    $firstGroup = ProductOptionGroup::factory()->create(['position' => 10]);
    $secondGroup = ProductOptionGroup::factory()->create(['position' => 20]);
    $firstValues = ProductOptionValue::factory()->count(2)->forGroup($firstGroup)->create();
    $secondValues = ProductOptionValue::factory()->count(3)->forGroup($secondGroup)->create();
    $firstTemplate = ProductOptionTemplate::factory()->create();
    $secondTemplate = ProductOptionTemplate::factory()->create();

    foreach ($firstValues as $value) {
        $firstTemplate->items()->create([
            'product_option_group_id' => $firstGroup->getKey(),
            'product_option_value_id' => $value->getKey(),
            'position' => 10,
        ]);
        $secondTemplate->items()->create([
            'product_option_group_id' => $firstGroup->getKey(),
            'product_option_value_id' => $value->getKey(),
            'position' => 10,
        ]);
    }

    foreach ($secondValues as $value) {
        $firstTemplate->items()->create([
            'product_option_group_id' => $secondGroup->getKey(),
            'product_option_value_id' => $value->getKey(),
            'position' => 20,
        ]);
    }

    $templates = ProductOptionTemplateResource::getEloquentQuery()
        ->whereKey([$firstTemplate->getKey(), $secondTemplate->getKey()])
        ->orderBy('id')
        ->get();

    foreach ($templates as $template) {
        expect($template->relationLoaded('items'))->toBeTrue();

        foreach ($template->items as $item) {
            expect($item->relationLoaded('group'))->toBeTrue()
                ->and($item->relationLoaded('value'))->toBeTrue();
        }
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $counts = $templates
        ->map(fn (ProductOptionTemplate $template): int => app(ProductOptionCombinationCalculator::class)
            ->countForTemplate($template))
        ->all();

    expect($counts)->toBe([6, 2])
        ->and(DB::getQueryLog())->toHaveCount(0);
});

test('template item labels use request local dictionaries instead of per item queries', function (): void {
    $groups = ProductOptionGroup::factory()->count(3)->create();
    $values = $groups->map(fn (ProductOptionGroup $group) => ProductOptionValue::factory()->forGroup($group)->create());
    $method = new ReflectionMethod(ProductOptionTemplateResource::class, 'itemLabel');
    Once::flush();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $labels = $groups->values()->map(fn (ProductOptionGroup $group, int $index): string => $method->invoke(null, [
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $values[$index]->getKey(),
    ]))->all();

    expect($labels)->toBe($groups->values()->map(fn (ProductOptionGroup $group, int $index): string => $group->title.': '.$values[$index]->title)->all())
        ->and(DB::getQueryLog())->toHaveCount(2);
});
