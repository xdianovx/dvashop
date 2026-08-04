<?php

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use App\Services\Catalog\ProductOptionCombinationCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('ProductOptionAdminService rejects forged non privileged mutations', function (): void {
    $group = ProductOptionGroup::factory()->create();
    $actors = [
        User::factory()->manager()->create(),
        User::factory()->create(),
        User::factory()->superAdmin()->inactive()->create(),
        User::factory()->superAdmin()->blocked()->create(),
    ];

    foreach ($actors as $actor) {
        expect(fn () => app(ProductOptionAdminService::class)->updateGroup($actor, $group, [
            ...$group->attributesToArray(),
            'title' => 'Подмена',
        ]))->toThrow(AuthorizationException::class);
    }

    expect($group->refresh()->title)->not->toBe('Подмена');
});

test('used option keys are immutable and a value cannot move to another group', function (): void {
    $admin = User::factory()->admin()->create();
    $group = ProductOptionGroup::factory()->create(['slug' => 'material', 'code' => 'material']);
    $otherGroup = ProductOptionGroup::factory()->create();
    $value = ProductOptionValue::factory()->forGroup($group)->create(['slug' => 'steel', 'code' => 'steel']);
    $template = ProductOptionTemplate::factory()->create();
    ProductOptionTemplateItem::query()->create([
        'product_option_template_id' => $template->getKey(),
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
        'position' => 0,
    ]);
    $service = app(ProductOptionAdminService::class);

    expect(fn () => $service->updateGroup($admin, $group, [
        ...$group->attributesToArray(),
        'slug' => 'changed-material',
    ]))->toThrow(ValidationException::class, 'используемой группы');

    expect(fn () => $service->updateValue($admin, $value, [
        ...$value->attributesToArray(),
        'slug' => 'changed-steel',
    ]))->toThrow(ValidationException::class, 'используемого значения');

    expect(fn () => $service->updateValue($admin, $value, [
        ...$value->attributesToArray(),
        'product_option_group_id' => $otherGroup->getKey(),
    ]))->toThrow(ValidationException::class, 'другую группу');

    expect($group->refresh()->slug)->toBe('material')
        ->and($value->refresh()->slug)->toBe('steel')
        ->and($value->product_option_group_id)->toBe($group->getKey());
});

test('deactivation preserves template product variant relations and snapshots', function (): void {
    $admin = User::factory()->admin()->create();
    $group = ProductOptionGroup::factory()->create();
    $value = ProductOptionValue::factory()->forGroup($group)->create();
    $template = ProductOptionTemplate::factory()->create();
    $item = ProductOptionTemplateItem::query()->create([
        'product_option_template_id' => $template->getKey(),
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
        'position' => 0,
    ]);
    $product = Product::factory()->create(['product_option_template_id' => $template->getKey()]);
    $variant = ProductVariant::factory()->forProduct($product)->create([
        'options' => ['legacy' => 'Сохранено'],
    ]);
    $selection = $variant->variantOptionValues()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
    ]);
    $service = app(ProductOptionAdminService::class);

    $service->setValueActive($admin, $value, false);
    $service->setGroupActive($admin, $group, false);
    $service->setTemplateActive($admin, $template, false);

    expect($value->refresh()->is_active)->toBeFalse()
        ->and($group->refresh()->is_active)->toBeFalse()
        ->and($template->refresh()->is_active)->toBeFalse()
        ->and($item->refresh()->exists)->toBeTrue()
        ->and($product->refresh()->product_option_template_id)->toBe($template->getKey())
        ->and($selection->refresh()->exists)->toBeTrue()
        ->and($variant->refresh()->options)->toBe(['legacy' => 'Сохранено']);
});

test('active template update enforces the shared combination limit atomically', function (): void {
    $admin = User::factory()->admin()->create();
    $template = ProductOptionTemplate::factory()->create(['is_active' => true]);
    $initialGroup = ProductOptionGroup::factory()->create();
    $initialValue = ProductOptionValue::factory()->forGroup($initialGroup)->create();
    $initialItem = ProductOptionTemplateItem::query()->create([
        'product_option_template_id' => $template->getKey(),
        'product_option_group_id' => $initialGroup->getKey(),
        'product_option_value_id' => $initialValue->getKey(),
        'position' => 1,
    ]);
    $items = [];

    foreach (range(1, 3) as $groupPosition) {
        $group = ProductOptionGroup::factory()->create(['position' => $groupPosition]);

        foreach (range(1, 5) as $valuePosition) {
            $value = ProductOptionValue::factory()->forGroup($group)->create(['position' => $valuePosition]);
            $items[] = [
                'product_option_group_id' => $group->getKey(),
                'product_option_value_id' => $value->getKey(),
                'position' => $valuePosition,
            ];
        }
    }

    expect(ProductOptionCombinationCalculator::MAX_COMBINATIONS)->toBe(100)
        ->and(app(ProductOptionCombinationCalculator::class)->countForItems($items))->toBe(125);

    expect(fn () => app(ProductOptionAdminService::class)->updateTemplate(
        $admin,
        $template,
        [...$template->attributesToArray(), 'title' => 'Не должно сохраниться'],
        $items,
    ))->toThrow(ValidationException::class, 'более 100 комбинаций');

    expect($template->refresh()->title)->not->toBe('Не должно сохраниться')
        ->and($template->items()->count())->toBe(1)
        ->and($template->items()->firstOrFail()->is($initialItem))->toBeTrue();
});

test('template item validation rejects a foreign value without partial state', function (): void {
    $admin = User::factory()->admin()->create();
    $template = ProductOptionTemplate::factory()->create();
    $group = ProductOptionGroup::factory()->create();
    $otherGroup = ProductOptionGroup::factory()->create();
    $value = ProductOptionValue::factory()->forGroup($otherGroup)->create();
    $originalTitle = $template->title;

    expect(fn () => app(ProductOptionAdminService::class)->updateTemplate(
        $admin,
        $template,
        [...$template->attributesToArray(), 'title' => 'Частичное изменение'],
        [[
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $value->getKey(),
            'position' => 1,
        ]],
    ))->toThrow(ValidationException::class, 'не принадлежит');

    expect($template->refresh()->title)->toBe($originalTitle)
        ->and($template->items()->count())->toBe(0);
});

test('duplicate inactive and conflicting default values are rejected server side', function (): void {
    $admin = User::factory()->admin()->create();
    $group = ProductOptionGroup::factory()->create();
    ProductOptionValue::factory()->forGroup($group)->default()->create();
    $service = app(ProductOptionAdminService::class);

    expect(fn () => $service->createValue($admin, $group, [
        'title' => 'Второе default',
        'slug' => 'second-default',
        'code' => 'second-default',
        'is_default' => true,
        'is_active' => true,
        'position' => 20,
    ]))->toThrow(ValidationException::class, 'только одно значение по умолчанию');

    $inactive = ProductOptionValue::factory()->forGroup($group)->create(['is_active' => false]);
    $template = ProductOptionTemplate::factory()->create(['is_active' => true]);

    expect(fn () => $service->updateTemplate(
        $admin,
        $template,
        $template->attributesToArray(),
        [[
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $inactive->getKey(),
            'position' => 1,
        ]],
    ))->toThrow(ValidationException::class, 'только активную группу');

    expect($template->items()->count())->toBe(0);

    $duplicateTemplate = ProductOptionTemplate::factory()->create(['is_active' => false]);
    $active = ProductOptionValue::factory()->forGroup($group)->create();

    expect(fn () => $service->updateTemplate(
        $admin,
        $duplicateTemplate,
        $duplicateTemplate->attributesToArray(),
        [[
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $active->getKey(),
            'position' => 1,
        ], [
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $active->getKey(),
            'position' => 2,
        ]],
    ))->toThrow(ValidationException::class, 'уже добавлено');

    expect($duplicateTemplate->items()->count())->toBe(0);
});

test('template item updates never mutate or generate product variants', function (): void {
    $admin = User::factory()->admin()->create();
    $group = ProductOptionGroup::factory()->create();
    $first = ProductOptionValue::factory()->forGroup($group)->create();
    $second = ProductOptionValue::factory()->forGroup($group)->create();
    $template = ProductOptionTemplate::factory()->create(['is_active' => true]);
    ProductOptionTemplateItem::query()->create([
        'product_option_template_id' => $template->getKey(),
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $first->getKey(),
        'position' => 10,
    ]);
    $product = Product::factory()->create(['product_option_template_id' => $template->getKey()]);
    $variant = ProductVariant::factory()->forProduct($product)->create([
        'sku' => 'UNCHANGED-SKU',
        'price' => 12345,
        'stock_quantity' => 7,
    ]);

    app(ProductOptionAdminService::class)->updateTemplate(
        $admin,
        $template,
        $template->attributesToArray(),
        [[
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $second->getKey(),
            'position' => 20,
        ]],
    );

    expect($product->variants()->count())->toBe(1)
        ->and($variant->refresh()->sku)->toBe('UNCHANGED-SKU')
        ->and($variant->price)->toBe('12345.00')
        ->and($variant->stock_quantity)->toBe(7)
        ->and($template->items()->where('product_option_value_id', $second->getKey())->exists())->toBeTrue();
});

test('group applies_to changes must remain compatible with every linked template', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(ProductOptionAdminService::class);
    $conflictingGroup = ProductOptionGroup::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_ALL,
    ]);
    $conflictingValue = ProductOptionValue::factory()->forGroup($conflictingGroup)->create();
    $genericTemplate = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_GENERIC,
    ]);
    $genericTemplate->items()->create([
        'product_option_group_id' => $conflictingGroup->getKey(),
        'product_option_value_id' => $conflictingValue->getKey(),
        'position' => 0,
    ]);

    expect(fn () => $service->updateGroup($admin, $conflictingGroup, [
        ...$conflictingGroup->attributesToArray(),
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
    ]))->toThrow(ValidationException::class, 'несовместимыми шаблонами');

    expect($conflictingGroup->refresh()->applies_to)->toBe(ProductOptionGroup::APPLIES_ALL)
        ->and($genericTemplate->refresh()->applies_to)->toBe(ProductOptionGroup::APPLIES_GENERIC);

    $safeGroup = ProductOptionGroup::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_ALL,
    ]);
    $safeValue = ProductOptionValue::factory()->forGroup($safeGroup)->create();
    $autoTemplate = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
    ]);
    $autoTemplate->items()->create([
        'product_option_group_id' => $safeGroup->getKey(),
        'product_option_value_id' => $safeValue->getKey(),
        'position' => 0,
    ]);

    $service->updateGroup($admin, $safeGroup, [
        ...$safeGroup->attributesToArray(),
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
    ]);

    expect($safeGroup->refresh()->applies_to)->toBe(ProductOptionGroup::APPLIES_AUTO_PART);
});

test('template scope and part type changes reject incompatible products without replacing items', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(ProductOptionAdminService::class);
    $firstPartType = PartType::factory()->create();
    $secondPartType = PartType::factory()->create();
    $group = ProductOptionGroup::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_ALL,
    ]);
    $firstValue = ProductOptionValue::factory()->forGroup($group)->create();
    $secondValue = ProductOptionValue::factory()->forGroup($group)->create();
    $template = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => null,
    ]);
    $item = $template->items()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $firstValue->getKey(),
        'position' => 0,
    ]);
    $product = Product::factory()->forPartType($firstPartType)->create([
        'product_option_template_id' => $template->getKey(),
    ]);
    $replacementItems = [[
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $secondValue->getKey(),
        'position' => 10,
    ]];

    expect(fn () => $service->updateTemplate($admin, $template, [
        ...$template->attributesToArray(),
        'applies_to' => ProductOptionGroup::APPLIES_GENERIC,
    ], $replacementItems))->toThrow(ValidationException::class, 'несовместимым товаром');

    expect(fn () => $service->updateTemplate($admin, $template, [
        ...$template->attributesToArray(),
        'part_type_id' => $secondPartType->getKey(),
    ], $replacementItems))->toThrow(ValidationException::class, 'несовместимым товаром');

    expect($template->refresh()->applies_to)->toBe(ProductOptionGroup::APPLIES_AUTO_PART)
        ->and($template->part_type_id)->toBeNull()
        ->and($template->items()->count())->toBe(1)
        ->and($template->items()->firstOrFail()->is($item))->toBeTrue()
        ->and($product->refresh()->product_option_template_id)->toBe($template->getKey());

    $updated = $service->updateTemplate($admin, $template, [
        ...$template->attributesToArray(),
        'part_type_id' => $firstPartType->getKey(),
    ], [[
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $firstValue->getKey(),
        'position' => 0,
    ]]);

    expect($updated->part_type_id)->toBe($firstPartType->getKey())
        ->and($product->refresh()->product_option_template_id)->toBe($template->getKey());
});

test('template defaults are active unique per exact scope and switch atomically', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(ProductOptionAdminService::class);
    $group = ProductOptionGroup::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_ALL,
    ]);
    $value = ProductOptionValue::factory()->forGroup($group)->create();
    $oldDefault = ProductOptionTemplate::factory()->default()->create();
    $newDefault = ProductOptionTemplate::factory()->create();
    $otherScopeDefault = ProductOptionTemplate::factory()->default()->create([
        'applies_to' => ProductOptionGroup::APPLIES_GENERIC,
    ]);

    foreach ([$oldDefault, $newDefault, $otherScopeDefault] as $template) {
        $template->items()->create([
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $value->getKey(),
            'position' => 0,
        ]);
    }

    $service->updateTemplate($admin, $newDefault, [
        ...$newDefault->attributesToArray(),
        'is_default' => true,
    ], $newDefault->items()->get()->map->attributesToArray()->all());

    expect($oldDefault->refresh()->is_default)->toBeFalse()
        ->and($newDefault->refresh()->is_default)->toBeTrue()
        ->and($otherScopeDefault->refresh()->is_default)->toBeTrue();

    $createdDefault = $service->createTemplate($admin, [
        'title' => 'Новый default',
        'slug' => 'new-default-template',
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => null,
        'is_default' => true,
        'is_active' => true,
        'position' => 50,
    ], [[
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
        'position' => 0,
    ]]);

    expect($newDefault->refresh()->is_default)->toBeFalse()
        ->and($createdDefault->is_default)->toBeTrue()
        ->and($otherScopeDefault->refresh()->is_default)->toBeTrue();

    expect(fn () => $service->setTemplateActive($admin, $createdDefault, false))
        ->toThrow(ValidationException::class, 'должен оставаться активным');

    expect($createdDefault->refresh()->is_active)->toBeTrue();
});

test('template validation allows part type only for active auto part defaults', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(ProductOptionAdminService::class);
    $partType = PartType::factory()->create();

    expect(fn () => $service->createTemplate($admin, [
        'title' => 'Неверный scope',
        'slug' => 'invalid-part-type-scope',
        'applies_to' => ProductOptionGroup::APPLIES_ALL,
        'part_type_id' => $partType->getKey(),
        'is_default' => false,
        'is_active' => true,
        'position' => 0,
    ], []))->toThrow(ValidationException::class, 'только для шаблона автодеталей');

    expect(fn () => $service->createTemplate($admin, [
        'title' => 'Неактивный default',
        'slug' => 'inactive-default-template',
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => null,
        'is_default' => true,
        'is_active' => false,
        'position' => 0,
    ], []))->toThrow(ValidationException::class, 'должен быть активным');

    expect(ProductOptionTemplate::query()->whereIn('slug', [
        'invalid-part-type-scope',
        'inactive-default-template',
    ])->exists())->toBeFalse();
});

test('both default value mutation paths serialize on the parent group row', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(ProductOptionAdminService::class);
    $group = ProductOptionGroup::factory()->create();
    $first = ProductOptionValue::factory()->forGroup($group)->default()->create();
    $second = ProductOptionValue::factory()->forGroup($group)->create();

    expect(fn () => $service->updateValue($admin, $second, [
        ...$second->attributesToArray(),
        'is_default' => true,
    ]))->toThrow(ValidationException::class, 'только одно значение по умолчанию');

    $service->updateValue($admin, $first, [
        ...$first->attributesToArray(),
        'is_default' => false,
    ]);
    $service->updateValue($admin, $second, [
        ...$second->attributesToArray(),
        'is_default' => true,
    ]);

    $source = file_get_contents(app_path('Services/Catalog/ProductOptionAdminService.php'));

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and(substr_count($source, 'ProductOptionGroup::query()->lockForUpdate()->findOrFail'))->toBeGreaterThanOrEqual(2);
});
