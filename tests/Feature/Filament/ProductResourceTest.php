<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\VehicleGeneration;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');

    $this->actingAs(User::factory()->superAdmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function productResourceCategory(): ProductCategory
{
    $root = ProductCategory::factory()->create([
        'title' => 'Кузовные детали',
        'slug' => 'kuzovnye-detali',
    ]);
    $section = ProductCategory::factory()->forParent($root)->create([
        'title' => 'Ремонтные элементы кузова',
        'slug' => 'remontnye-elementy-kuzova',
    ]);

    return ProductCategory::factory()->forParent($section)->create([
        'title' => 'Пороги',
        'slug' => 'porogi',
    ])->refresh();
}

function productResourcePartType(ProductCategory $category): PartType
{
    return PartType::factory()->forCategory($category)->create([
        'title' => 'Порог',
        'default_image_key' => 'porog',
    ])->refresh();
}

test('auto part exposes part type and cars tab while generic product hides them', function () {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type' => ProductType::AutoPart->value,
            'part_type_id' => $partType->getKey(),
        ], 'form')
        ->assertFormFieldVisible('part_type_id')
        ->assertFormFieldExists('part_type_id', fn (Select $field): bool => $field->isRequired() && $field->isDehydrated())
        ->assertSchemaComponentVisible('product-fitments-tab')
        ->assertSet('data.part_type_id', $partType->getKey());

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type' => ProductType::AutoPart->value,
            'part_type_id' => $partType->getKey(),
        ], 'form')
        ->set('data.product_type', ProductType::Generic->value)
        ->assertFormFieldHidden('part_type_id')
        ->assertFormFieldExists('part_type_id', fn (Select $field): bool => ! $field->isRequired() && ! $field->isDehydrated())
        ->assertSchemaComponentHidden('product-fitments-tab')
        ->assertSet('data.part_type_id', null)
        ->assertSet('data.fitments', []);
});

test('generic product create discards stale part type and fitments', function () {
    $undoRepeaterFake = Repeater::fake();
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $generation = VehicleGeneration::factory()->create();

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'product_type' => ProductType::Generic->value,
                'title' => 'Обычный товар без применимости',
                'slug' => 'generic-with-stale-fitments',
                'product_category_id' => $category->getKey(),
                'part_type_id' => $partType->getKey(),
                'status' => ProductStatus::Active->value,
                'position' => 0,
                'is_featured' => false,
                'stock_status' => StockStatus::InStock->value,
                'variants' => [[
                    'sku' => 'GENERIC-BASE',
                    'title' => 'Основной вариант',
                    'price' => 2500,
                    'stock_quantity' => 5,
                    'stock_status' => StockStatus::InStock->value,
                    'is_default' => true,
                    'is_active' => true,
                    'options' => [],
                ]],
                'fitments' => [[
                    'vehicle_generation_id' => $generation->getKey(),
                    'note' => 'Эта связь не должна сохраниться',
                    'is_primary' => true,
                ]],
            ], 'form')
            ->assertSet('data.part_type_id', $partType->getKey())
            ->assertSet('data.fitments.0.vehicle_generation_id', $generation->getKey())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();
    } finally {
        $undoRepeaterFake();
    }

    $product = Product::query()->where('slug', 'generic-with-stale-fitments')->firstOrFail();

    expect($product->product_type)->toBe(ProductType::Generic)
        ->and($product->part_type_id)->toBeNull()
        ->and($product->fitments()->count())->toBe(0)
        ->and($product->defaultVariant?->price)->toBe('2500.00')
        ->and($product->defaultVariant?->stock_quantity)->toBe(5);
});

test('technical product fields are readonly and isolated from the main tab', function () {
    Livewire::test(CreateProduct::class)
        ->assertFormFieldDisabled('import_key')
        ->assertFormFieldDisabled('import_source')
        ->assertFormFieldDisabled('last_import_run_id')
        ->assertSchemaComponentExists('product-main-tab')
        ->assertSchemaComponentExists('product-technical-tab');

    $source = file_get_contents(app_path('Filament/Resources/Products/Schemas/ProductForm.php'));
    $mainTab = str($source)->between('private static function mainTab()', 'private static function priceAndStockTab()')->toString();
    $technicalTab = str($source)->between('private static function technicalTab()', '/** @param array<string, mixed> $arguments */')->toString();

    expect($mainTab)
        ->not->toContain("TextInput::make('import_key')")
        ->not->toContain("TextInput::make('import_source')")
        ->not->toContain("TextInput::make('last_import_run_id')")
        ->and($technicalTab)
        ->toContain("TextInput::make('import_key')")
        ->toContain("TextInput::make('import_source')")
        ->toContain("TextInput::make('last_import_run_id')")
        ->toContain('->disabled()');
});

test('create product accepts multiple manual gallery images and keeps one visible main image', function () {
    $undoRepeaterFake = Repeater::fake();
    $category = productResourceCategory();
    $partType = productResourcePartType($category);

    $uploads = [
        UploadedFile::fake()->image('front.jpg', 900, 700),
        UploadedFile::fake()->image('side.png', 700, 900),
    ];

    try {
        $component = Livewire::test(CreateProduct::class)
            ->fillForm([
                'product_type' => ProductType::AutoPart->value,
                'title' => 'Порог тестовый с галереей',
                'slug' => 'porog-test-gallery',
                'product_category_id' => $category->getKey(),
                'part_type_id' => $partType->getKey(),
                'status' => ProductStatus::Active->value,
                'position' => 10,
                'is_featured' => false,
                'price' => 12500,
                'old_price' => null,
                'stock_status' => StockStatus::InStock->value,
                'variants' => [[
                    'sku' => 'POROG-TEST-BASE',
                    'title' => 'Основной комплект',
                    'price' => 12500,
                    'old_price' => null,
                    'stock_quantity' => 3,
                    'stock_status' => StockStatus::InStock->value,
                    'is_default' => true,
                    'is_active' => true,
                    'options' => [],
                ]],
            ], 'form')
            ->assertSet('data.title', 'Порог тестовый с галереей')
            ->assertSet('data.part_type_id', $partType->getKey())
            ->assertSet('data.variants.0.price', 12500);

        $component
            ->set('data.gallery_uploads', $uploads)
            ->assertSet('data.title', 'Порог тестовый с галереей')
            ->assertSet('data.part_type_id', $partType->getKey())
            ->assertSet('data.variants.0.price', 12500)
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();
    } finally {
        $undoRepeaterFake();
    }

    $product = Product::query()->where('slug', 'porog-test-gallery')->firstOrFail();
    $images = $product->images()->orderBy('position')->get();

    expect($images)->toHaveCount(2)
        ->and($images->pluck('source_type')->unique()->all())->toBe([ProductImage::SOURCE_MANUAL])
        ->and($images->where('is_main', true))->toHaveCount(1)
        ->and($images->where('is_visible', true))->toHaveCount(2)
        ->and($images->firstWhere('is_main', true)?->is_visible)->toBeTrue()
        ->and($images->every(fn (ProductImage $image): bool => $image->mime === 'image/webp'))->toBeTrue()
        ->and($images->every(fn (ProductImage $image): bool => str_starts_with($image->path, 'uploads/products/'.$product->getKey().'/')))->toBeTrue()
        ->and($images->every(fn (ProductImage $image): bool => Storage::disk('public')->exists($image->path)))->toBeTrue()
        ->and(Storage::disk('public')->allFiles('uploads/products/pending/manual'))->toBe([]);
});

test('compact price fields create one default variant without opening variants section', function () {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type' => ProductType::AutoPart->value,
            'title' => 'Порог с основной ценой',
            'slug' => 'porog-primary-price',
            'product_category_id' => $category->getKey(),
            'part_type_id' => $partType->getKey(),
            'status' => ProductStatus::Active->value,
            'position' => 0,
            'is_featured' => false,
            'sku' => 'PRIMARY-SKU',
            'price' => 14900,
            'old_price' => 15900,
            'default_stock_quantity' => 7,
            'stock_status' => StockStatus::InStock->value,
        ], 'form')
        ->assertSet('data.variants', [])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $product = Product::query()->where('slug', 'porog-primary-price')->firstOrFail();
    $variant = $product->defaultVariant()->firstOrFail();

    expect($product->variants()->count())->toBe(1)
        ->and($variant->sku)->toBe('PRIMARY-SKU')
        ->and($variant->price)->toBe('14900.00')
        ->and($variant->old_price)->toBe('15900.00')
        ->and($variant->stock_quantity)->toBe(7)
        ->and($variant->stock_status)->toBe(StockStatus::InStock)
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->is_active)->toBeTrue();
});

test('edit product accepts a batch of new manual gallery images', function () {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->withDefaultVariant()
        ->create();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.gallery_uploads', [
            UploadedFile::fake()->image('edit-front.jpg', 800, 600),
            UploadedFile::fake()->image('edit-side.png', 600, 800),
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $images = $product->images()->orderBy('position')->get();

    expect($images)->toHaveCount(2)
        ->and($images->where('source_type', ProductImage::SOURCE_MANUAL))->toHaveCount(2)
        ->and($images->where('is_main', true))->toHaveCount(1)
        ->and($images->where('is_visible', true))->toHaveCount(2)
        ->and($images->every(fn (ProductImage $image): bool => Storage::disk('public')->exists($image->path)))->toBeTrue();
});

test('switching an existing product to generic removes stored fitments', function () {
    $undoRepeaterFake = Repeater::fake();
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->withDefaultVariant()
        ->create();

    ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration(VehicleGeneration::factory()->create())
        ->primary()
        ->create();

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.product_type', ProductType::Generic->value)
            ->assertFormFieldHidden('part_type_id')
            ->assertSchemaComponentHidden('product-fitments-tab')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->refresh()->product_type)->toBe(ProductType::Generic)
        ->and($product->part_type_id)->toBeNull()
        ->and($product->fitments()->count())->toBe(0);
});

test('repeated edit save does not create a second default variant', function () {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->withDefaultVariant()
        ->create();
    $variant = $product->defaultVariant()->firstOrFail();
    $variant->forceFill([
        'sku' => 'MANUAL-DEFAULT-SKU',
        'price' => 17850,
        'old_price' => 18900,
        'stock_quantity' => 4,
        'stock_status' => StockStatus::PreOrder,
    ])->save();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertSet('data.sku', 'MANUAL-DEFAULT-SKU')
        ->assertSet('data.price', '17850.00')
        ->assertSet('data.old_price', '18900.00')
        ->assertSet('data.default_stock_quantity', 4)
        ->assertSet('data.stock_status', StockStatus::PreOrder->value)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($product->variants()->count())->toBe(1)
        ->and($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($variant->refresh()->sku)->toBe('MANUAL-DEFAULT-SKU')
        ->and($variant->price)->toBe('17850.00')
        ->and($variant->old_price)->toBe('18900.00')
        ->and($variant->stock_quantity)->toBe(4)
        ->and($variant->stock_status)->toBe(StockStatus::PreOrder);
});

test('product resource query and table keep store category and part type separate', function () {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create();

    $record = ProductResource::getEloquentQuery()->findOrFail($product->getKey());
    $tableSource = file_get_contents(app_path('Filament/Resources/Products/Tables/ProductsTable.php'));

    expect($record->relationLoaded('category'))->toBeTrue()
        ->and($record->relationLoaded('partType'))->toBeTrue()
        ->and($record->category?->full_title)->toContain('Кузовные детали / Ремонтные элементы кузова / Пороги')
        ->and($record->partType?->full_title)->toBe('Порог')
        ->and($tableSource)->toContain("ImageColumn::make('main_image_url')")
        ->toContain("TextColumn::make('category.full_title')")
        ->toContain("TextColumn::make('partType.full_title')")
        ->toContain("SelectFilter::make('product_type')")
        ->toContain("Filter::make('without_images')")
        ->and(ProductResource::getRelations())->toBe([]);
});
