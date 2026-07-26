<?php

use App\Filament\Resources\PartTypes\Pages\EditPartType;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\VehicleGenerations\Pages\EditVehicleGeneration;
use App\Filament\Resources\VehicleMakes\Pages\EditVehicleMake;
use App\Filament\Resources\VehicleModels\Pages\EditVehicleModel;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->actingAs(User::factory()->superAdmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function seoResourceData(string $suffix): array
{
    return [
        'meta_title' => 'Meta '.$suffix,
        'meta_description' => 'Description '.$suffix,
        'seo_h1' => 'H1 '.$suffix,
        'seo_text' => 'SEO text '.$suffix,
        'canonical_url' => 'https://example.test/'.strtolower($suffix),
        'noindex' => true,
        'og_title' => 'OG '.$suffix,
        'og_description' => 'OG description '.$suffix,
    ];
}

test('ProductCategoryResource displays validates and saves SEO fields', function () {
    $category = ProductCategory::factory()->create();

    Livewire::test(EditProductCategory::class, ['record' => $category->getKey()])
        ->assertFormFieldExists('seo_h1')
        ->assertFormFieldExists('og_image')
        ->fillForm(['canonical_url' => 'invalid-url'])
        ->call('save')
        ->assertHasFormErrors(['canonical_url' => 'url'])
        ->fillForm(seoResourceData('Category'))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->refresh()->seo_h1)->toBe('H1 Category')
        ->and($category->noindex)->toBeTrue();
});

test('PartTypeResource displays and saves SEO fields', function () {
    $partType = PartType::factory()->create();

    Livewire::test(EditPartType::class, ['record' => $partType->getKey()])
        ->assertFormFieldExists('seo_h1')
        ->assertFormFieldExists('canonical_url')
        ->fillForm(seoResourceData('PartType'))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($partType->refresh()->og_title)->toBe('OG PartType')
        ->and($partType->noindex)->toBeTrue();
});

test('vehicle resources display and save SEO fields', function () {
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->for($make, 'make')->create();
    $generation = VehicleGeneration::factory()->for($model, 'model')->create();

    foreach ([
        [EditVehicleMake::class, $make, 'Make'],
        [EditVehicleModel::class, $model, 'Model'],
        [EditVehicleGeneration::class, $generation, 'Generation'],
    ] as [$page, $record, $suffix]) {
        Livewire::test($page, ['record' => $record->getKey()])
            ->assertFormFieldExists('seo_h1')
            ->assertFormFieldExists('noindex')
            ->fillForm(seoResourceData($suffix))
            ->call('save')
            ->assertHasNoFormErrors();

        expect($record->refresh()->seo_h1)->toBe('H1 '.$suffix)
            ->and($record->noindex)->toBeTrue();
    }
});
