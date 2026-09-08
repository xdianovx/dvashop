<?php

use App\Filament\Resources\ProductOptionGroups\Pages\EditProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\RelationManagers\ValuesRelationManager;
use App\Filament\Resources\ProductOptionTemplates\Pages\CreateProductOptionTemplate;
use App\Filament\Resources\ProductOptionTemplates\Pages\ListProductOptionTemplates;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function usedFeedbackOptions(): array
{
    $group = ProductOptionGroup::factory()->create(['description' => 'Старое описание группы']);
    $value = ProductOptionValue::factory()->forGroup($group)->create(['description' => 'Старое описание значения']);
    $template = ProductOptionTemplate::factory()->create(['is_default' => true]);
    $template->items()->create(['product_option_group_id' => $group->id, 'product_option_value_id' => $value->id, 'position' => 0]);

    return [$group, $value, $template];
}

test('empty template submit shows title slug and active items errors immediately', function (): void {
    Livewire::test(CreateProductOptionTemplate::class)
        ->call('create')
        ->assertHasFormErrors(['title' => 'required', 'slug' => 'required', 'template_items' => 'required'])
        ->assertNotified('Не удалось выполнить действие');
    expect(ProductOptionTemplate::count())->toBe(0);
});

test('untouched nested template row validates both required selectors on submit', function (): void {
    $undo = Repeater::fake();
    try {
        Livewire::test(CreateProductOptionTemplate::class)
            ->fillForm(['title' => 'Шаблон', 'slug' => 'nested-required', 'template_items' => [[
                'product_option_group_id' => null, 'product_option_value_id' => null, 'position' => 0,
            ]]])
            ->call('create')
            ->assertHasFormErrors(['template_items.0.product_option_group_id' => 'required', 'template_items.0.product_option_value_id' => 'required'])
            ->assertNotified('Не удалось выполнить действие');
        expect(ProductOptionTemplate::count())->toBe(0);
    } finally {
        $undo();
    }
});

test('inactive empty template remains valid and optional fields stay optional', function (): void {
    Livewire::test(CreateProductOptionTemplate::class)
        ->fillForm(['title' => 'Черновик', 'slug' => 'draft', 'is_active' => false, 'template_items' => []])
        ->call('create')->assertHasNoFormErrors();
    expect(ProductOptionTemplate::query()->sole()->is_active)->toBeFalse();
});

test('used group identifiers are readonly and forged updates visibly fail while names remain editable', function (string $field): void {
    [$group] = usedFeedbackOptions();
    $old = $group->$field;
    Livewire::test(EditProductOptionGroup::class, ['record' => $group->id])
        ->assertFormFieldExists('slug', fn (TextInput $input): bool => $input->isReadOnly())
        ->assertFormFieldExists('code', fn (TextInput $input): bool => $input->isReadOnly())
        ->assertFormFieldDoesNotExist('description')
        ->set('data.'.$field, 'tampered-key')->call('save')
        ->assertHasErrors(['slug'])->assertNotified('Не удалось выполнить действие');
    expect($group->refresh()->$field)->toBe($old);
    Livewire::test(EditProductOptionGroup::class, ['record' => $group->id])
        ->fillForm(['title' => 'Новое название'])->call('save')->assertHasNoFormErrors();
    expect($group->refresh()->title)->toBe('Новое название')->and($group->description)->toBe('Старое описание группы');
})->with(['slug', 'code']);

test('used value identifiers are readonly and forged modal submissions visibly fail', function (string $field): void {
    [$group, $value] = usedFeedbackOptions();
    $old = $value->$field;
    $component = Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditProductOptionGroup::class])
        ->mountTableAction('edit', $value);
    $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
    expect($schema->getComponent('slug', withHidden: true)->isReadOnly())->toBeTrue()
        ->and($schema->getComponent('code', withHidden: true)->isReadOnly())->toBeTrue();
    $component->setTableActionData([$field => 'tampered-key'])->callMountedTableAction()
        ->assertHasErrors(['slug'])->assertNotified('Не удалось выполнить действие');
    expect($value->refresh()->$field)->toBe($old);
    Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditProductOptionGroup::class])
        ->callTableAction('edit', $value, data: ['title' => 'Новое значение'])->assertHasNoTableActionErrors();
    expect($value->refresh()->title)->toBe('Новое значение')->and($value->description)->toBe('Старое описание значения');
})->with(['slug', 'code']);

test('unused group and value identifiers can still be edited through the domain service', function (): void {
    $group = ProductOptionGroup::factory()->create();
    $value = ProductOptionValue::factory()->forGroup($group)->create();
    $service = app(ProductOptionAdminService::class);
    $service->updateGroup(auth()->user(), $group, [...$group->attributesToArray(), 'slug' => 'new-group', 'code' => 'new-group']);
    $service->updateValue(auth()->user(), $value, [...$value->attributesToArray(), 'slug' => 'new-value', 'code' => 'new-value']);
    expect($group->refresh()->slug)->toBe('new-group')->and($group->code)->toBe('new-group')
        ->and($value->refresh()->slug)->toBe('new-value')->and($value->code)->toBe('new-value');
    Livewire::test(EditProductOptionGroup::class, ['record' => $group->id])
        ->assertFormFieldExists('slug', fn (TextInput $input): bool => ! $input->isReadOnly());
});

test('domain failure in a table action without fields has a visible reason', function (): void {
    [, , $template] = usedFeedbackOptions();
    Livewire::test(ListProductOptionTemplates::class)->callTableAction('toggle_active', $template)
        ->assertHasErrors(['is_active'])
        ->assertNotified(Notification::make()->danger()->title('Не удалось выполнить действие')
            ->body('Шаблон по умолчанию должен оставаться активным. Сначала снимите признак «По умолчанию».')->persistent());
    expect($template->refresh()->is_active)->toBeTrue();
});

test('removing explicit product variants is rolled back with a visible reason', function (): void {
    $product = Product::factory()->generic()->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create();
    $before = $product->refresh()->getAttributes();
    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->set('data.variants', [])->set('data.title', 'Не сохранять')
        ->call('save')->assertHasErrors(['variant'])->assertNotified('Не удалось выполнить действие');
    expect($product->refresh()->getAttributes())->toBe($before)
        ->and($product->variants()->pluck('id')->all())->toBe([$variant->id]);
});

test('unexpected exceptions remain exceptions rather than validation notifications', function (): void {
    $product = Product::factory()->generic()->create();
    Product::saving(function (): never {
        throw new RuntimeException('Unexpected storage outage');
    });
    expect(fn () => Livewire::test(EditProduct::class, ['record' => $product->id])->call('save'))
        ->toThrow(RuntimeException::class, 'Unexpected storage outage');
});
