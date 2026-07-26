<?php

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ProductOption seeder creates the default auto part option catalog idempotently', function () {
    $this->seed(ProductOptionSeeder::class);

    $custom = ProductOptionValue::query()->create([
        'product_option_group_id' => ProductOptionGroup::query()->where('slug', 'material')->value('id'),
        'title' => 'Нержавеющая сталь',
        'slug' => 'stainless',
        'code' => 'stainless',
        'position' => 90,
    ]);

    $this->seed(ProductOptionSeeder::class);

    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();

    expect(ProductOptionGroup::query()->orderBy('position')->pluck('slug')->all())
        ->toBe(['profile', 'position', 'material', 'thickness'])
        ->and(ProductOptionValue::query()->whereNot('slug', 'stainless')->count())->toBe(9)
        ->and(ProductOptionValue::query()->whereKey($custom)->exists())->toBeTrue()
        ->and(ProductOptionTemplate::query()->where('slug', 'default_auto_part')->count())->toBe(1)
        ->and($template->title)->toBe('Стандартные опции автодетали')
        ->and($template->is_default)->toBeTrue()
        ->and(ProductOptionTemplateItem::query()->where('product_option_template_id', $template->getKey())->count())->toBe(9)
        ->and(ProductOptionValue::query()->where('slug', 'full')->firstOrFail()->is_default)->toBeTrue()
        ->and(ProductOptionValue::query()->where('slug', 'both')->firstOrFail()->is_default)->toBeTrue()
        ->and(ProductOptionValue::query()->where('slug', 'galvanized')->firstOrFail()->is_default)->toBeTrue()
        ->and(ProductOptionValue::query()->where('slug', '1mm')->firstOrFail()->is_default)->toBeTrue();
});
