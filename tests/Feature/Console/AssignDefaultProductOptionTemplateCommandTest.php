<?php

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
