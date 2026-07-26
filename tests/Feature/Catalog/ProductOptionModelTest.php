<?php

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('ProductOption models expose values template items and variant selections', function () {
    $group = ProductOptionGroup::factory()->create([
        'title' => 'Материал',
        'slug' => 'material',
        'code' => 'material',
        'position' => 10,
    ]);
    $value = ProductOptionValue::factory()->forGroup($group)->default()->create([
        'title' => 'Оцинковка',
        'slug' => 'galvanized',
        'code' => 'galvanized',
    ]);
    $template = ProductOptionTemplate::factory()->create();
    ProductOptionTemplateItem::query()->create([
        'product_option_template_id' => $template->getKey(),
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
    ]);
    $variant = ProductVariant::factory()->create();
    $variant->variantOptionValues()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
    ]);

    expect($group->values()->pluck('id')->all())->toBe([$value->getKey()])
        ->and($template->items()->count())->toBe(1)
        ->and($template->values()->pluck('product_option_values.id')->all())->toBe([$value->getKey()])
        ->and($variant->optionValues()->pluck('product_option_values.id')->all())->toBe([$value->getKey()]);
});

test('ProductVariantOption rejects a second value from the same group', function () {
    $group = ProductOptionGroup::factory()->create();
    $first = ProductOptionValue::factory()->forGroup($group)->create();
    $second = ProductOptionValue::factory()->forGroup($group)->create();
    $variant = ProductVariant::factory()->create();

    $variant->variantOptionValues()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $first->getKey(),
    ]);

    expect(fn () => $variant->variantOptionValues()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $second->getKey(),
    ]))->toThrow(ValidationException::class, 'только одно значение');
});

test('ProductVariant option summary and JSON snapshot use normalized option values with legacy fallback', function () {
    $profile = ProductOptionGroup::factory()->create([
        'title' => 'Профиль',
        'slug' => 'profile',
        'code' => 'profile',
        'position' => 10,
    ]);
    $material = ProductOptionGroup::factory()->create([
        'title' => 'Материал',
        'slug' => 'material',
        'code' => 'material',
        'position' => 20,
    ]);
    $full = ProductOptionValue::factory()->forGroup($profile)->create(['title' => 'Полный']);
    $galvanized = ProductOptionValue::factory()->forGroup($material)->create(['title' => 'Оцинковка']);
    $variant = ProductVariant::factory()->create([
        'options' => ['legacy' => 'Старое значение'],
    ]);

    expect($variant->optionSummary())->toBe('legacy: Старое значение');

    $variant->variantOptionValues()->createMany([
        ['product_option_group_id' => $material->getKey(), 'product_option_value_id' => $galvanized->getKey()],
        ['product_option_group_id' => $profile->getKey(), 'product_option_value_id' => $full->getKey()],
    ]);
    $variant->syncOptionsSnapshotFromValues();

    expect($variant->refresh()->optionSummary())->toBe('Профиль: Полный; Материал: Оцинковка')
        ->and($variant->options)->toBe([
            'profile' => ['group' => 'Профиль', 'value' => 'Полный'],
            'material' => ['group' => 'Материал', 'value' => 'Оцинковка'],
        ]);
});

test('Product can belong to an option template', function () {
    $template = ProductOptionTemplate::factory()->create();
    $product = Product::factory()->create(['product_option_template_id' => $template->getKey()]);

    expect($product->optionTemplate->is($template))->toBeTrue()
        ->and($template->products()->whereKey($product)->exists())->toBeTrue();
});

test('ProductOption relationship factories create internally consistent group and value references', function () {
    $templateItem = ProductOptionTemplateItem::factory()->create();
    $variantSelection = ProductVariantOptionValue::factory()->create();

    expect($templateItem->value->product_option_group_id)->toBe($templateItem->product_option_group_id)
        ->and($variantSelection->value->product_option_group_id)->toBe($variantSelection->product_option_group_id);
});
