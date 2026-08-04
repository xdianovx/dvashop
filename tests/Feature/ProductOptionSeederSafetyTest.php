<?php

use App\Models\PartType;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<class-string, int> */
function productOptionSeederTableCounts(): array
{
    return [
        ProductOptionGroup::class => ProductOptionGroup::query()->count(),
        ProductOptionValue::class => ProductOptionValue::query()->count(),
        ProductOptionTemplate::class => ProductOptionTemplate::query()->count(),
        ProductOptionTemplateItem::class => ProductOptionTemplateItem::query()->count(),
    ];
}

/** @param array<class-string, int> $counts */
function expectProductOptionSeederTableCounts(array $counts): void
{
    foreach ($counts as $model => $count) {
        expect($model::query()->count(), $model)->toBe($count);
    }

    expect(ProductOptionTemplateItem::query()->count())->toBe(0);
}

test('ProductOptionSeeder preserves manual settings user records and variant selections', function (): void {
    $this->seed(ProductOptionSeeder::class);

    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $group = ProductOptionGroup::query()->where('slug', 'material')->firstOrFail();
    $value = ProductOptionValue::query()
        ->whereBelongsTo($group, 'group')
        ->where('slug', 'galvanized')
        ->firstOrFail();
    $template->update(['title' => 'Ручной шаблон', 'position' => 777, 'is_active' => false]);
    $group->update(['title' => 'Ручная группа', 'position' => 778, 'is_active' => false]);
    $value->update(['title' => 'Ручное значение', 'position' => 779, 'is_active' => false]);
    $customGroup = ProductOptionGroup::factory()->create(['slug' => 'custom-user-group']);
    $customValue = ProductOptionValue::factory()->forGroup($customGroup)->create(['slug' => 'custom-user-value']);
    $customTemplate = ProductOptionTemplate::factory()->create(['slug' => 'custom-user-template']);
    $variant = ProductVariant::factory()->create();
    $selection = $variant->variantOptionValues()->create([
        'product_option_group_id' => $customGroup->getKey(),
        'product_option_value_id' => $customValue->getKey(),
    ]);
    $counts = [
        ProductOptionGroup::class => ProductOptionGroup::query()->count(),
        ProductOptionValue::class => ProductOptionValue::query()->count(),
        ProductOptionTemplate::class => ProductOptionTemplate::query()->count(),
        ProductOptionTemplateItem::class => ProductOptionTemplateItem::query()->count(),
    ];

    $this->seed(ProductOptionSeeder::class);

    expect($template->refresh()->title)->toBe('Ручной шаблон')
        ->and($template->position)->toBe(777)
        ->and($template->is_active)->toBeFalse()
        ->and($group->refresh()->title)->toBe('Ручная группа')
        ->and($group->position)->toBe(778)
        ->and($group->is_active)->toBeFalse()
        ->and($value->refresh()->title)->toBe('Ручное значение')
        ->and($value->position)->toBe(779)
        ->and($value->is_active)->toBeFalse()
        ->and(ProductOptionGroup::query()->whereKey($customGroup)->exists())->toBeTrue()
        ->and(ProductOptionValue::query()->whereKey($customValue)->exists())->toBeTrue()
        ->and(ProductOptionTemplate::query()->whereKey($customTemplate)->exists())->toBeTrue()
        ->and($selection->refresh()->exists)->toBeTrue();

    foreach ($counts as $model => $count) {
        expect($model::query()->count(), $model)->toBe($count);
    }
});

test('ProductOptionSeeder recreates only missing standard records', function (): void {
    $this->seed(ProductOptionSeeder::class);
    $group = ProductOptionGroup::query()->where('slug', 'profile')->firstOrFail();
    $missing = ProductOptionValue::query()->whereBelongsTo($group, 'group')->where('slug', 'lower')->firstOrFail();
    ProductOptionTemplateItem::query()->where('product_option_value_id', $missing->getKey())->delete();
    $missing->delete();

    $this->seed(ProductOptionSeeder::class);

    $restored = ProductOptionValue::query()->whereBelongsTo($group, 'group')->where('slug', 'lower')->firstOrFail();

    expect($restored->title)->toBe('Нижняя часть')
        ->and(ProductOptionTemplateItem::query()->where('product_option_value_id', $restored->getKey())->exists())->toBeTrue()
        ->and(ProductOptionGroup::query()->count())->toBe(4)
        ->and(ProductOptionValue::query()->count())->toBe(9)
        ->and(ProductOptionTemplate::query()->count())->toBe(1);
});

test('ProductOptionSeeder resolves system groups and values by code without rewriting manual fields', function (): void {
    $group = ProductOptionGroup::factory()->create([
        'title' => 'Ручной профиль',
        'slug' => 'manual-profile-slug',
        'code' => 'profile',
        'position' => 777,
        'is_active' => false,
    ]);
    $value = ProductOptionValue::factory()->forGroup($group)->create([
        'title' => 'Ручной полный',
        'slug' => 'manual-full-slug',
        'code' => 'full',
        'position' => 778,
        'is_active' => false,
    ]);

    $this->seed(ProductOptionSeeder::class);

    expect(ProductOptionGroup::query()->where('code', 'profile')->count())->toBe(1)
        ->and(ProductOptionGroup::query()->where('slug', 'profile')->count())->toBe(0)
        ->and($group->refresh()->title)->toBe('Ручной профиль')
        ->and($group->slug)->toBe('manual-profile-slug')
        ->and($group->position)->toBe(777)
        ->and($group->is_active)->toBeFalse()
        ->and(ProductOptionValue::query()
            ->whereBelongsTo($group, 'group')
            ->where('code', 'full')
            ->count())->toBe(1)
        ->and($value->refresh()->title)->toBe('Ручной полный')
        ->and($value->slug)->toBe('manual-full-slug')
        ->and($value->position)->toBe(778)
        ->and($value->is_active)->toBeFalse();
});

test('ProductOptionSeeder rolls back everything on a slug code collision', function (): void {
    ProductOptionGroup::factory()->create([
        'slug' => 'profile',
        'code' => 'first-profile-code',
    ]);
    ProductOptionGroup::factory()->create([
        'slug' => 'second-profile-slug',
        'code' => 'profile',
    ]);
    $groupCount = ProductOptionGroup::query()->count();

    expect(fn () => $this->seed(ProductOptionSeeder::class))
        ->toThrow(LogicException::class, 'slug и code принадлежат разным записям');

    expect(ProductOptionGroup::query()->count())->toBe($groupCount)
        ->and(ProductOptionValue::query()->count())->toBe(0)
        ->and(ProductOptionTemplate::query()->count())->toBe(0)
        ->and(ProductOptionTemplateItem::query()->count())->toBe(0);
});

test('ProductOptionSeeder preserves a manual default when restoring a standard default value', function (): void {
    $group = ProductOptionGroup::factory()->create([
        'slug' => 'manual-profile-slug',
        'code' => 'profile',
    ]);
    $manualDefault = ProductOptionValue::factory()->forGroup($group)->default()->create([
        'slug' => 'manual-default',
        'code' => 'manual-default',
    ]);

    $this->seed(ProductOptionSeeder::class);

    $restoredStandard = ProductOptionValue::query()
        ->whereBelongsTo($group, 'group')
        ->where('slug', 'full')
        ->firstOrFail();

    expect($manualDefault->refresh()->is_default)->toBeTrue()
        ->and($restoredStandard->is_default)->toBeFalse()
        ->and($group->values()->where('is_default', true)->count())->toBe(1);
});

test('ProductOptionSeeder rejects a generic canonical auto part template and rolls back', function (): void {
    $template = ProductOptionTemplate::factory()->create([
        'slug' => 'default_auto_part',
        'applies_to' => ProductOptionGroup::APPLIES_GENERIC,
        'part_type_id' => null,
    ]);
    $counts = productOptionSeederTableCounts();

    expect(fn () => $this->seed(ProductOptionSeeder::class))
        ->toThrow(LogicException::class, 'несовместимую область применения');

    expectProductOptionSeederTableCounts($counts);
    expect($template->refresh()->applies_to)->toBe(ProductOptionGroup::APPLIES_GENERIC);
});

test('ProductOptionSeeder rejects a part type scoped canonical template and rolls back', function (): void {
    $partType = PartType::factory()->create();
    $template = ProductOptionTemplate::factory()->create([
        'slug' => 'default_auto_part',
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => $partType->getKey(),
    ]);
    $counts = productOptionSeederTableCounts();

    expect(fn () => $this->seed(ProductOptionSeeder::class))
        ->toThrow(LogicException::class, 'пустой part_type_id');

    expectProductOptionSeederTableCounts($counts);
    expect($template->refresh()->part_type_id)->toBe($partType->getKey());
});

test('ProductOptionSeeder rejects a generic system group resolved by code and rolls back', function (): void {
    $group = ProductOptionGroup::factory()->create([
        'title' => 'Ручная generic-группа',
        'slug' => 'manual-profile-slug',
        'code' => 'profile',
        'applies_to' => ProductOptionGroup::APPLIES_GENERIC,
        'position' => 777,
        'is_active' => false,
    ]);
    $counts = productOptionSeederTableCounts();

    expect(fn () => $this->seed(ProductOptionSeeder::class))
        ->toThrow(LogicException::class, 'Системная группа «profile» имеет несовместимую область применения');

    expectProductOptionSeederTableCounts($counts);
    expect($group->refresh()->applies_to)->toBe(ProductOptionGroup::APPLIES_GENERIC)
        ->and($group->slug)->toBe('manual-profile-slug')
        ->and($group->position)->toBe(777)
        ->and($group->is_active)->toBeFalse();
});
