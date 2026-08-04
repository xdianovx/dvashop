<?php

use App\Enums\ProductType;
use App\Models\PartType;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Services\Catalog\ProductOptionTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('default option template resolver follows the deterministic auto part priority', function (): void {
    $partType = PartType::factory()->create();
    $otherPartType = PartType::factory()->create();
    $allDefault = ProductOptionTemplate::factory()->default()->create([
        'applies_to' => ProductOptionGroup::APPLIES_ALL,
        'part_type_id' => null,
    ]);
    $autoDefault = ProductOptionTemplate::factory()->default()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => null,
    ]);
    $specificDefault = ProductOptionTemplate::factory()->default()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => $partType->getKey(),
    ]);
    $otherSpecific = ProductOptionTemplate::factory()->default()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => $otherPartType->getKey(),
    ]);
    $resolver = app(ProductOptionTemplateResolver::class);

    expect($resolver->resolveDefaultForAutoPart($partType->getKey())?->is($specificDefault))->toBeTrue()
        ->and($resolver->resolveDefaultForAutoPart($otherPartType->getKey())?->is($otherSpecific))->toBeTrue()
        ->and($resolver->resolveDefaultForAutoPart(null)?->is($autoDefault))->toBeTrue();

    $specificDefault->update(['is_active' => false]);
    $autoDefault->update(['is_active' => false]);

    expect($resolver->resolveDefaultForAutoPart($partType->getKey())?->is($allDefault))->toBeTrue();
});

test('default option template resolver uses the legacy slug only when no managed default exists', function (): void {
    $partType = PartType::factory()->create();
    $legacy = ProductOptionTemplate::factory()->create([
        'slug' => 'default_auto_part',
        'is_default' => false,
        'is_active' => true,
    ]);
    $resolver = app(ProductOptionTemplateResolver::class);

    expect($resolver->resolveDefaultForAutoPart($partType->getKey())?->is($legacy))->toBeTrue()
        ->and($resolver->isCompatible($legacy, ProductType::AutoPart, $partType->getKey()))->toBeTrue();

    $managed = ProductOptionTemplate::factory()->default()->create();

    expect($resolver->resolveDefaultForAutoPart($partType->getKey())?->is($managed))->toBeTrue();
});
