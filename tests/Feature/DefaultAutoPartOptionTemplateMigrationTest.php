<?php

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductOptionCombinationCalculator;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function defaultAutoPartMembershipMigration(): object
{
    return require database_path('migrations/2026_10_08_153000_normalize_default_auto_part_template_membership.php');
}

test('legacy lncreased membership migration removes only unused system membership', function (): void {
    $this->seed(ProductOptionSeeder::class);

    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $profile = ProductOptionGroup::query()->where('code', 'profile')->firstOrFail();
    $legacy = $profile->values()->create([
        'title' => 'Увеличенный',
        'slug' => 'lncreased',
        'code' => 'lncreased',
        'is_active' => true,
    ]);
    $template->items()->create([
        'product_option_group_id' => $profile->getKey(),
        'product_option_value_id' => $legacy->getKey(),
        'position' => 999,
    ]);
    $custom = ProductOptionTemplate::factory()->create();
    $custom->items()->create([
        'product_option_group_id' => $profile->getKey(),
        'product_option_value_id' => $legacy->getKey(),
        'position' => 10,
    ]);

    defaultAutoPartMembershipMigration()->up();
    defaultAutoPartMembershipMigration()->up();

    expect($template->items()->count())->toBe(9)
        ->and($template->values()->where('code', 'lncreased')->exists())->toBeFalse()
        ->and($legacy->fresh())->not->toBeNull()
        ->and($custom->items()->where('product_option_value_id', $legacy->getKey())->exists())->toBeTrue()
        ->and(app(ProductOptionCombinationCalculator::class)->countForTemplate($template->fresh()))->toBe(24);
});

test('legacy membership migration aborts when lncreased is used by a variant', function (): void {
    $this->seed(ProductOptionSeeder::class);

    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $profile = ProductOptionGroup::query()->where('code', 'profile')->firstOrFail();
    $legacy = $profile->values()->create([
        'title' => 'Увеличенный',
        'slug' => 'lncreased',
        'code' => 'lncreased',
        'is_active' => true,
    ]);
    $template->items()->create([
        'product_option_group_id' => $profile->getKey(),
        'product_option_value_id' => $legacy->getKey(),
        'position' => 999,
    ]);
    $variant = ProductVariant::factory()->create();
    $variant->variantOptionValues()->create([
        'product_option_group_id' => $profile->getKey(),
        'product_option_value_id' => $legacy->getKey(),
    ]);

    expect(fn () => defaultAutoPartMembershipMigration()->up())
        ->toThrow(RuntimeException::class, 'используется вариантами товаров');

    expect($template->items()->where('product_option_value_id', $legacy->getKey())->exists())->toBeTrue()
        ->and($variant->variantOptionValues()->where('product_option_value_id', $legacy->getKey())->exists())->toBeTrue();
});
