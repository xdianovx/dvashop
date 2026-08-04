<?php

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ProductOption backfill command supports dry run and preserves generic and manual templates', function () {
    $this->seed(ProductOptionSeeder::class);

    $defaultTemplate = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $manualTemplate = ProductOptionTemplate::factory()->create(['slug' => 'manual-auto-part']);
    $autoPart = Product::factory()->withDefaultVariant()->create(['product_option_template_id' => null]);
    $generic = Product::factory()->generic()->withDefaultVariant()->create(['product_option_template_id' => null]);
    $manual = Product::factory()->withDefaultVariant()->create([
        'product_option_template_id' => $manualTemplate->getKey(),
    ]);
    $variantCount = ProductVariant::query()->count();

    $this->artisan('products:assign-default-option-template')
        ->expectsOutputToContain('Автодеталей без шаблона: 1')
        ->expectsOutputToContain('Dry-run завершён. База данных не изменялась.')
        ->assertExitCode(0);

    expect($autoPart->fresh()->product_option_template_id)->toBeNull();

    $this->artisan('products:assign-default-option-template --apply')
        ->expectsOutputToContain('Шаблон назначен товарам: 1')
        ->expectsOutputToContain('Варианты автоматически не создавались.')
        ->assertExitCode(0);

    expect($autoPart->fresh()->product_option_template_id)->toBe($defaultTemplate->getKey())
        ->and($generic->fresh()->product_option_template_id)->toBeNull()
        ->and($manual->fresh()->product_option_template_id)->toBe($manualTemplate->getKey())
        ->and(ProductVariant::query()->count())->toBe($variantCount);
});

test('ProductOption backfill command uses the shared part specific default resolver', function (): void {
    $this->seed(ProductOptionSeeder::class);
    $partType = PartType::factory()->create();
    $specific = ProductOptionTemplate::factory()->default()->create([
        'part_type_id' => $partType->getKey(),
    ]);
    $specificProduct = Product::factory()->forPartType($partType)->create([
        'product_option_template_id' => null,
    ]);
    $fallbackProduct = Product::factory()->create([
        'part_type_id' => null,
        'product_option_template_id' => null,
    ]);
    $fallback = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();

    $this->artisan('products:assign-default-option-template --apply')
        ->assertExitCode(0);

    expect($specificProduct->refresh()->product_option_template_id)->toBe($specific->getKey())
        ->and($fallbackProduct->refresh()->product_option_template_id)->toBe($fallback->getKey());
});
