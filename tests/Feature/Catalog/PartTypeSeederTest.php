<?php

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);
});

test('part type seeder creates the complete tree with category assignments', function () {
    $this->seed(PartTypeSeeder::class);

    $expected = [
        'porog' => ['Порог', 0],
        'arka' => ['Арка', 0],
        'arka/zadniaia' => ['Арка / Задняя', 1],
        'arka/peredniaia' => ['Арка / Передняя', 1],
        'arka/vnutrenniaia' => ['Арка / Внутренняя', 1],
        'arka/vnutrenniaia-universalnaia' => ['Арка / Внутренняя универсальная', 1],
        'arka/karman-zadniaia' => ['Арка / Карман задняя', 1],
        'penka' => ['Пенка', 0],
        'penka/zadnei-dveri' => ['Пенка / Задней двери', 1],
        'penka/perednei-dveri' => ['Пенка / Передней двери', 1],
        'penka/bagazhnika' => ['Пенка / Багажника', 1],
        'lonzheron' => ['Лонжерон', 0],
        'remkomplekt-pola' => ['Ремкомплект пола', 0],
        'tortsevaia-zaglushka' => ['Торцевая заглушка', 0],
        'usilitel' => ['Усилитель', 0],
        'usilitel/soedinitel-porogov' => ['Усилитель / соединитель порогов', 1],
    ];

    foreach ($expected as $fullSlug => [$fullTitle, $depth]) {
        $partType = PartType::query()->where('full_slug', $fullSlug)->first();

        expect($partType)->not->toBeNull()
            ->and($partType->full_title)->toBe($fullTitle)
            ->and($partType->depth)->toBe($depth)
            ->and($partType->product_category_id)->not->toBeNull();
    }

    expect(PartType::query()->whereNull('parent_id')->count())->toBe(7)
        ->and(PartType::query()->whereNotNull('parent_id')->count())->toBe(9)
        ->and(PartType::query()->count())->toBe(16);
});

test('part type seeder assigns exact default image keys only to final types', function () {
    $this->seed(PartTypeSeeder::class);

    $expectedKeys = [
        'porog' => 'porog',
        'arka/karman-zadniaia' => 'arka-karman-zadniaia',
        'arka/peredniaia' => 'arka-peredniaia',
        'arka/vnutrenniaia' => 'arka-vnutrenniaia',
        'arka/vnutrenniaia-universalnaia' => 'arka-vnutrenniaia-universalnaia',
        'arka/zadniaia' => 'arka-zadniaia',
        'lonzheron' => 'lonzeron',
        'penka/bagazhnika' => 'penka-bagaznika',
        'penka/perednei-dveri' => 'penka-perednei-dveri',
        'penka/zadnei-dveri' => 'penka-zadnei-dveri',
        'remkomplekt-pola' => 'remkomplekt-pola',
        'tortsevaia-zaglushka' => 'torcevaia-zagluska',
        'usilitel/soedinitel-porogov' => 'usilitel-soedinitel-porogov',
    ];

    foreach ($expectedKeys as $fullSlug => $imageKey) {
        expect(PartType::query()->where('full_slug', $fullSlug)->value('default_image_key'))->toBe($imageKey);
    }

    expect(PartType::query()->whereIn('full_slug', ['arka', 'penka', 'usilitel'])->whereNotNull('default_image_key')->exists())
        ->toBeFalse();
});

test('part type seeder is idempotent and restores a soft deleted type', function () {
    $this->seed(PartTypeSeeder::class);
    $partType = PartType::query()->where('full_slug', 'arka/zadniaia')->firstOrFail();
    $partTypeId = $partType->id;
    $partType->delete();

    $this->seed(PartTypeSeeder::class);
    $this->seed(PartTypeSeeder::class);

    expect(PartType::withTrashed()->count())->toBe(16)
        ->and(PartType::query()->whereKey($partTypeId)->exists())->toBeTrue();
});

test('part type seeder does not create technical product categories or mutate products', function () {
    $category = ProductCategory::factory()->create(['title' => 'Автохимия']);
    $product = Product::factory()->forCategory($category)->generic()->create()->fresh();
    $attributesBefore = $product->getAttributes();

    $this->seed(PartTypeSeeder::class);

    expect(ProductCategory::query()->whereIn('full_slug', [
        'arka/zadniaia',
        'penka/zadnei-dveri',
    ])->exists())->toBeFalse()
        ->and($product->fresh()->getAttributes())->toBe($attributesBefore);
});
