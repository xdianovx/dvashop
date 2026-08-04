<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\ProductOptionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
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

/** @return array{0: ProductCategory, 1: PartType} */
function stage6ProductCatalog(): array
{
    $category = ProductCategory::factory()->create([
        'title' => 'Пороги Stage 6',
        'slug' => 'stage-6-porogi',
    ])->refresh();
    $partType = PartType::factory()->forCategory($category)->create([
        'title' => 'Порог Stage 6',
        'default_image_key' => 'porog',
    ])->refresh();

    return [$category, $partType];
}

/** @return array{make: VehicleMake, model: VehicleModel, generations: array<int, VehicleGeneration>} */
function stage6VehicleTree(string $suffix = 'A', int $generationCount = 2): array
{
    $make = VehicleMake::factory()->create(['title' => 'Марка '.$suffix]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Модель '.$suffix]);
    $generations = [];

    for ($index = 1; $index <= $generationCount; $index++) {
        $generations[] = VehicleGeneration::factory()->forVehicleModel($model)->create([
            'title' => 'Поколение '.$suffix.$index,
        ]);
    }

    return compact('make', 'model', 'generations');
}

/** @return array<string, mixed> */
function stage6BaseProductData(ProductCategory $category, PartType $partType, string $slug): array
{
    return [
        'product_type' => ProductType::AutoPart->value,
        'title' => 'Товар '.$slug,
        'slug' => $slug,
        'sku' => strtoupper($slug),
        'product_category_id' => $category->getKey(),
        'part_type_id' => $partType->getKey(),
        'status' => ProductStatus::Active->value,
        'position' => 0,
        'is_featured' => false,
        'price' => 12500,
        'old_price' => 13200,
        'default_stock_quantity' => 5,
        'stock_status' => StockStatus::InStock->value,
    ];
}

test('Stage 6 dependent fitment selectors save multiple consistent generations and reopen them', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $vehicle = stage6VehicleTree();

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-fitments'),
                'fitments' => collect($vehicle['generations'])->map(fn (VehicleGeneration $generation, int $index): array => [
                    'vehicle_make_id' => $vehicle['make']->getKey(),
                    'vehicle_model_id' => $vehicle['model']->getKey(),
                    'vehicle_generation_id' => $generation->getKey(),
                    'note' => 'Применяемость '.($index + 1),
                    'is_primary' => $index === 0,
                ])->all(),
            ], 'form')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $product = Product::query()->where('slug', 'stage-6-fitments')->firstOrFail();

        expect($product->fitments()->orderBy('vehicle_generation_id')->pluck('vehicle_generation_id')->all())
            ->toBe(collect($vehicle['generations'])->pluck('id')->sort()->values()->all());

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSet('data.fitments.record-'.$product->fitments()->oldest('id')->value('id').'.vehicle_make_id', $vehicle['make']->getKey())
            ->assertSet('data.fitments.record-'.$product->fitments()->oldest('id')->value('id').'.vehicle_model_id', $vehicle['model']->getKey())
            ->assertSet('data.fitments.record-'.$product->fitments()->oldest('id')->value('id').'.vehicle_generation_id', $vehicle['generations'][0]->getKey())
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->fitments()->count())->toBe(2);
    } finally {
        $undoRepeaterFake();
    }
});

test('Stage 6 AutoPart requires part type while Generic creates without part type or fitments', function () {
    [$category] = stage6ProductCatalog();
    $autoSlug = 'stage-6-auto-without-part-type';
    $genericSlug = 'stage-6-generic';

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type' => ProductType::AutoPart->value,
            'title' => 'Автодеталь без типа детали',
            'slug' => $autoSlug,
            'product_category_id' => $category->getKey(),
            'part_type_id' => null,
            'status' => ProductStatus::Active->value,
            'position' => 0,
            'price' => 1500,
            'stock_status' => StockStatus::InStock->value,
        ], 'form')
        ->call('create')
        ->assertHasFormErrors(['part_type_id' => 'required']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type' => ProductType::Generic->value,
            'title' => 'Обычный товар',
            'slug' => $genericSlug,
            'product_category_id' => $category->getKey(),
            'part_type_id' => null,
            'status' => ProductStatus::Active->value,
            'position' => 0,
            'price' => 1700,
            'stock_status' => StockStatus::InStock->value,
        ], 'form')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('slug', $autoSlug)->exists())->toBeFalse()
        ->and(Product::query()->where('slug', $genericSlug)->firstOrFail()->part_type_id)->toBeNull()
        ->and(Product::query()->where('slug', $genericSlug)->firstOrFail()->fitments()->count())->toBe(0);
});

test('Stage 6 fitments reject a model from another make a generation from another model blank rows and duplicates', function (string $case) {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $first = stage6VehicleTree('A', 1);
    $second = stage6VehicleTree('B', 1);
    $valid = [
        'vehicle_make_id' => $first['make']->getKey(),
        'vehicle_model_id' => $first['model']->getKey(),
        'vehicle_generation_id' => $first['generations'][0]->getKey(),
        'note' => null,
        'is_primary' => false,
    ];
    $fitments = match ($case) {
        'wrong model' => [[...$valid, 'vehicle_model_id' => $second['model']->getKey()]],
        'wrong generation' => [[...$valid, 'vehicle_generation_id' => $second['generations'][0]->getKey()]],
        'blank' => [[
            'vehicle_make_id' => null,
            'vehicle_model_id' => null,
            'vehicle_generation_id' => null,
            'note' => null,
            'is_primary' => false,
        ]],
        'duplicate' => [$valid, $valid],
    };
    $slug = 'stage-6-invalid-'.str_replace(' ', '-', $case);

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, $slug),
                'fitments' => $fitments,
            ], 'form')
            ->call('create')
            ->assertHasFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect(Product::query()->where('slug', $slug)->exists())->toBeFalse();
})->with(['wrong model', 'wrong generation', 'blank', 'duplicate']);

test('Stage 6 type switching preserves hidden fitments until explicit save and normalizes Generic', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $vehicle = stage6VehicleTree('switch', 1);
    $product = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create();
    $fitment = ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration($vehicle['generations'][0])
        ->create();

    try {
        $component = Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.product_type', ProductType::Generic->value)
            ->assertSet('data.part_type_id', $partType->getKey())
            ->assertSet('data.fitments.record-'.$fitment->getKey().'.vehicle_generation_id', $vehicle['generations'][0]->getKey());

        expect($fitment->fresh())->toBeInstanceOf(ProductFitment::class)
            ->and($product->refresh()->part_type_id)->toBe($partType->getKey());

        $component
            ->set('data.product_type', ProductType::AutoPart->value)
            ->assertSet('data.part_type_id', $partType->getKey())
            ->assertSet('data.fitments.record-'.$fitment->getKey().'.vehicle_generation_id', $vehicle['generations'][0]->getKey())
            ->set('data.product_type', ProductType::Generic->value)
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->refresh()->product_type)->toBe(ProductType::Generic)
        ->and($product->part_type_id)->toBeNull()
        ->and($product->fitments()->count())->toBe(0);
});

test('Stage 6 inactive or deleted current part type remains labelled and saveable', function (string $state) {
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create();

    if ($state === 'inactive') {
        $partType->update(['is_active' => false]);
    } else {
        $partType->deleteQuietly();
    }

    expect(ProductForm::partTypeOptions())->not->toHaveKey($partType->getKey())
        ->and(ProductForm::partTypeOptions($partType->getKey()))
        ->toHaveKey($partType->getKey())
        ->and(ProductForm::partTypeOptions($partType->getKey())[$partType->getKey()])
        ->toContain($state === 'inactive' ? 'неактивно' : 'удалено');

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertSet('data.part_type_id', $partType->getKey())
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->part_type_id)->toBe($partType->getKey());
})->with(['inactive', 'deleted']);

test('Stage 6 soft deleted vehicle hierarchy remains visible and saveable for an existing fitment', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $vehicle = stage6VehicleTree('deleted', 1);
    $product = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create();
    $fitment = ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration($vehicle['generations'][0])
        ->create();

    $vehicle['generations'][0]->deleteQuietly();
    $vehicle['model']->deleteQuietly();
    $vehicle['make']->deleteQuietly();

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSet('data.fitments.record-'.$fitment->getKey().'.vehicle_make_id', $vehicle['make']->getKey())
            ->assertSet('data.fitments.record-'.$fitment->getKey().'.vehicle_model_id', $vehicle['model']->getKey())
            ->assertSet('data.fitments.record-'.$fitment->getKey().'.vehicle_generation_id', $vehicle['generations'][0]->getKey())
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($fitment->fresh())->toBeInstanceOf(ProductFitment::class);
});

test('Stage 6 no-op edit preserves a variantless product gallery fitments category and part type', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $vehicle = stage6VehicleTree('no-op');
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create();
    $fitments = collect($vehicle['generations'])->map(fn (VehicleGeneration $generation): ProductFitment => ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration($generation)
        ->create());
    $images = ProductImage::factory()->count(2)->forProduct($product)->sequence(
        ['position' => 10, 'is_main' => true, 'is_visible' => true, 'checksum' => str_repeat('a', 64)],
        ['position' => 20, 'is_main' => false, 'is_visible' => true, 'checksum' => str_repeat('b', 64)],
    )->create();

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->refresh()->product_category_id)->toBe($category->getKey())
        ->and($product->part_type_id)->toBe($partType->getKey())
        ->and($product->variants()->count())->toBe(0)
        ->and($product->fitments()->orderBy('id')->pluck('id')->all())->toBe($fitments->pluck('id')->all())
        ->and($product->images()->orderBy('id')->pluck('id')->all())->toBe($images->pluck('id')->all())
        ->and($product->images()->orderBy('id')->pluck('position')->all())->toBe([10, 20]);
});

test('Stage 6 creates and reopens variants with unique SKU and normalized distinct option combinations', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->seed(ProductOptionSeeder::class);
    [$category, $partType] = stage6ProductCatalog();
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $profile = ProductOptionGroup::query()->where('slug', 'profile')->firstOrFail();
    $values = ProductOptionValue::query()->whereBelongsTo($profile, 'group')->orderBy('position')->take(2)->get();

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-variants'),
                'product_option_template_id' => $template->getKey(),
                'default_stock_quantity' => null,
                'variants' => $values->map(fn (ProductOptionValue $value, int $index): array => [
                    'sku' => 'STAGE6-VARIANT-'.($index + 1),
                    'title' => 'Вариант '.($index + 1),
                    'price' => 12000 + ($index * 500),
                    'old_price' => null,
                    'stock_quantity' => 3 + $index,
                    'stock_status' => StockStatus::InStock->value,
                    'is_default' => $index === 0,
                    'is_active' => true,
                    'options' => [],
                    'variantOptionValues' => [[
                        'product_option_group_id' => $profile->getKey(),
                        'product_option_value_id' => $value->getKey(),
                    ]],
                ])->all(),
            ], 'form')
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('slug', 'stage-6-variants')->firstOrFail();

        expect($product->variants()->count())->toBe(2)
            ->and($product->variants()->distinct()->count('sku'))->toBe(2)
            ->and($product->variants()->where('is_default', true)->count())->toBe(1)
            ->and($product->variants()->with('optionValues')->get()->every(fn (ProductVariant $variant): bool => $variant->optionValues->count() === 1))->toBeTrue();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSet('data.variants.record-'.$product->variants()->oldest('id')->value('id').'.sku', 'STAGE6-VARIANT-1')
            ->assertSet('data.variants.record-'.$product->variants()->latest('id')->value('id').'.sku', 'STAGE6-VARIANT-2');
    } finally {
        $undoRepeaterFake();
    }
});

test('Stage 6 rejects duplicate variant SKU and rolls back the entire product create', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $variant = [
        'sku' => 'STAGE6-DUPLICATE-SKU',
        'title' => 'Повтор',
        'price' => 1000,
        'stock_quantity' => 1,
        'stock_status' => StockStatus::InStock->value,
        'is_default' => false,
        'is_active' => true,
        'options' => [],
    ];

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-duplicate-sku'),
                'default_stock_quantity' => null,
                'variants' => [$variant, $variant],
            ], 'form')
            ->call('create')
            ->assertHasFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect(Product::query()->where('slug', 'stage-6-duplicate-sku')->exists())->toBeFalse();
});

test('Stage 6 rejects duplicate normalized option combinations and rolls back the entire product create', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->seed(ProductOptionSeeder::class);
    [$category, $partType] = stage6ProductCatalog();
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $profile = ProductOptionGroup::query()->where('slug', 'profile')->firstOrFail();
    $value = ProductOptionValue::query()->whereBelongsTo($profile, 'group')->firstOrFail();
    $variant = [
        'title' => 'Одинаковая комбинация',
        'price' => 1000,
        'stock_quantity' => 1,
        'stock_status' => StockStatus::InStock->value,
        'is_default' => false,
        'is_active' => true,
        'options' => [],
        'variantOptionValues' => [[
            'product_option_group_id' => $profile->getKey(),
            'product_option_value_id' => $value->getKey(),
        ]],
    ];

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-duplicate-options'),
                'product_option_template_id' => $template->getKey(),
                'default_stock_quantity' => null,
                'variants' => [
                    [...$variant, 'sku' => 'STAGE6-OPTION-COMBO-1', 'is_default' => true],
                    [...$variant, 'sku' => 'STAGE6-OPTION-COMBO-2'],
                ],
            ], 'form')
            ->call('create')
            ->assertHasFormErrors(['variants']);
    } finally {
        $undoRepeaterFake();
    }

    expect(Product::query()->where('slug', 'stage-6-duplicate-options')->exists())->toBeFalse();
});

test('Stage 6 explicitly removing one fitment and one variant preserves the remaining records', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $vehicle = stage6VehicleTree('delete');
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create();
    $fitments = collect($vehicle['generations'])->map(fn (VehicleGeneration $generation): ProductFitment => ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration($generation)
        ->create());
    $variants = ProductVariant::factory()->count(2)->forProduct($product)->sequence(
        ['sku' => 'STAGE6-KEEP-VARIANT', 'is_default' => true],
        ['sku' => 'STAGE6-DELETE-VARIANT', 'is_default' => false],
    )->create();
    $remainingFitment = $fitments->first();
    $remainingVariant = $variants->first();

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.fitments', [
                'record-'.$remainingFitment->getKey() => [
                    'vehicle_make_id' => $vehicle['make']->getKey(),
                    'vehicle_model_id' => $vehicle['model']->getKey(),
                    'vehicle_generation_id' => $remainingFitment->vehicle_generation_id,
                    'note' => $remainingFitment->note,
                    'is_primary' => $remainingFitment->is_primary,
                ],
            ])
            ->set('data.variants', [
                'record-'.$remainingVariant->getKey() => [
                    'sku' => $remainingVariant->sku,
                    'title' => $remainingVariant->title,
                    'price' => $remainingVariant->price,
                    'old_price' => $remainingVariant->old_price,
                    'stock_quantity' => $remainingVariant->stock_quantity,
                    'stock_status' => $remainingVariant->stock_status->value,
                    'is_default' => true,
                    'is_active' => true,
                    'options' => $remainingVariant->options,
                    'variantOptionValues' => [],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->fitments()->pluck('id')->all())->toBe([$remainingFitment->getKey()])
        ->and($product->variants()->pluck('id')->all())->toBe([$remainingVariant->getKey()])
        ->and($remainingVariant->refresh()->is_default)->toBeTrue();
});

test('Stage 6 invalid pending gallery path cannot partially create a product', function () {
    [$category, $partType] = stage6ProductCatalog();
    Storage::disk('public')->put('uploads/products/not-pending/unsafe.jpg', 'not-an-upload');

    Livewire::test(CreateProduct::class)
        ->fillForm(stage6BaseProductData($category, $partType, 'stage-6-invalid-upload'), 'form')
        ->set('data.gallery_uploads', ['uploads/products/not-pending/unsafe.jpg'])
        ->call('create')
        ->assertHasFormErrors(['gallery_uploads']);

    expect(Product::query()->where('slug', 'stage-6-invalid-upload')->exists())->toBeFalse();
});

test('Stage 6 product table searches variant SKU and filters type part type status and availability', function () {
    [$category, $partType] = stage6ProductCatalog();
    $generic = Product::factory()->forCategory($category)->generic()->create([
        'title' => 'Универсальный товар',
        'sku' => 'STAGE6-GENERIC-SKU',
        'stock_status' => StockStatus::OutOfStock,
    ]);
    $autoPart = Product::factory()->forCategory($category)->forPartType($partType)->create([
        'title' => 'Автодеталь с вариантом',
        'sku' => 'STAGE6-AUTO-SKU',
        'status' => ProductStatus::Draft,
        'stock_status' => StockStatus::PreOrder,
    ]);
    ProductVariant::factory()->forProduct($autoPart)->create(['sku' => 'STAGE6-NESTED-VARIANT']);

    Livewire::test(ListProducts::class)
        ->searchTable('STAGE6-NESTED-VARIANT')
        ->assertCanSeeTableRecords([$autoPart])
        ->assertCanNotSeeTableRecords([$generic]);

    Livewire::test(ListProducts::class)
        ->filterTable('product_type', ProductType::Generic->value)
        ->assertCanSeeTableRecords([$generic])
        ->assertCanNotSeeTableRecords([$autoPart]);

    Livewire::test(ListProducts::class)
        ->filterTable('part_type_id', $partType->getKey())
        ->filterTable('status', ProductStatus::Draft->value)
        ->filterTable('stock_status', StockStatus::PreOrder->value)
        ->assertCanSeeTableRecords([$autoPart])
        ->assertCanNotSeeTableRecords([$generic]);
});

test('Stage 6 Filament product edit leaves cart and order snapshots immutable', function () {
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create([
        'title' => 'Исходный товар',
        'price' => 5100,
    ]);
    $variant = $product->defaultVariant()->firstOrFail();
    $variant->forceFill(['options' => ProductVariant::technicalOptions()])->save();
    $cartItem = CartItem::factory()->forVariant($variant)->create([
        'title_snapshot' => 'Исторический товар',
        'sku_snapshot' => 'HISTORY-SKU',
        'price_snapshot' => 4900,
    ]);
    $order = Order::factory()->create();
    $orderItem = OrderItem::factory()->for($order)->create([
        'product_id' => $product->getKey(),
        'product_variant_id' => $variant->getKey(),
        'title_snapshot' => 'Исторический заказ',
        'sku_snapshot' => 'ORDER-HISTORY-SKU',
        'price_snapshot' => 4700,
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->fillForm(['title' => 'Новое название', 'price' => 6200])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cartItem->refresh()->title_snapshot)->toBe('Исторический товар')
        ->and($cartItem->sku_snapshot)->toBe('HISTORY-SKU')
        ->and($cartItem->price_snapshot)->toBe('4900.00')
        ->and($orderItem->refresh()->title_snapshot)->toBe('Исторический заказ')
        ->and($orderItem->sku_snapshot)->toBe('ORDER-HISTORY-SKU')
        ->and($orderItem->price_snapshot)->toBe('4700.00')
        ->and($product->refresh()->title)->toBe('Новое название')
        ->and($product->price)->toBe('6200.00')
        ->and($variant->refresh()->price)->toBe('6200.00');
});

test('Stage 6 explicit default variant edits persist without compact field overwrite', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create([
        'sku' => 'PARENT-SKU',
        'price' => 9000,
    ]);
    $default = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'CHILD-A',
        'price' => 1000,
        'stock_quantity' => 2,
        'stock_status' => StockStatus::InStock,
    ]);
    ProductVariant::factory()->forProduct($product)->create([
        'sku' => 'CHILD-B',
        'price' => 2000,
        'stock_quantity' => 4,
        'stock_status' => StockStatus::PreOrder,
    ]);

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.variants.record-'.$default->getKey().'.sku', 'CHILD-A-EDITED')
            ->set('data.variants.record-'.$default->getKey().'.price', 1350)
            ->set('data.variants.record-'.$default->getKey().'.old_price', 1450)
            ->set('data.variants.record-'.$default->getKey().'.stock_quantity', 7)
            ->set('data.variants.record-'.$default->getKey().'.stock_status', StockStatus::OutOfStock->value)
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($default->refresh()->sku)->toBe('CHILD-A-EDITED')
        ->and($default->price)->toBe('1350.00')
        ->and($default->old_price)->toBe('1450.00')
        ->and($default->stock_quantity)->toBe(7)
        ->and($default->stock_status)->toBe(StockStatus::OutOfStock)
        ->and($product->refresh()->sku)->toBe('PARENT-SKU')
        ->and($product->price)->toBe('1350.00');
});

test('Stage 6 switching explicit default preserves each variant values and projects the new default', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create(['price' => 9999]);
    $first = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'SWITCH-A',
        'price' => 1100,
        'stock_quantity' => 1,
        'stock_status' => StockStatus::InStock,
    ]);
    $second = ProductVariant::factory()->forProduct($product)->create([
        'sku' => 'SWITCH-B',
        'price' => 2200,
        'old_price' => 2400,
        'stock_quantity' => 8,
        'stock_status' => StockStatus::PreOrder,
    ]);

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.variants.record-'.$second->getKey().'.is_default', true)
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($first->refresh()->is_default)->toBeFalse()
        ->and($first->sku)->toBe('SWITCH-A')
        ->and($first->price)->toBe('1100.00')
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($second->sku)->toBe('SWITCH-B')
        ->and($second->price)->toBe('2200.00')
        ->and($second->old_price)->toBe('2400.00')
        ->and($second->stock_quantity)->toBe(8)
        ->and($second->stock_status)->toBe(StockStatus::PreOrder)
        ->and($product->refresh()->price)->toBe('2200.00');
});

test('Stage 6 variantless create with explicit variants never copies compact values into the first variant', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-explicit-source'),
                'sku' => 'EXPLICIT-PARENT',
                'price' => 9900,
                'old_price' => 10900,
                'default_stock_quantity' => 99,
                'variants' => [
                    [
                        'sku' => 'EXPLICIT-A',
                        'title' => 'A',
                        'price' => 1200,
                        'old_price' => 1400,
                        'stock_quantity' => 2,
                        'stock_status' => StockStatus::InStock->value,
                        'is_default' => true,
                        'is_active' => true,
                        'options' => [],
                    ],
                    [
                        'sku' => 'EXPLICIT-B',
                        'title' => 'B',
                        'price' => 2300,
                        'old_price' => null,
                        'stock_quantity' => 5,
                        'stock_status' => StockStatus::PreOrder->value,
                        'is_default' => false,
                        'is_active' => true,
                        'options' => [],
                    ],
                ],
            ], 'form')
            ->call('create')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    $product = Product::query()->where('slug', 'stage-6-explicit-source')->firstOrFail();
    $first = $product->variants()->where('sku', 'EXPLICIT-A')->firstOrFail();
    $second = $product->variants()->where('sku', 'EXPLICIT-B')->firstOrFail();

    expect($product->variants()->count())->toBe(2)
        ->and($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($product->sku)->toBe('EXPLICIT-PARENT')
        ->and($product->price)->toBe('1200.00')
        ->and($first->price)->toBe('1200.00')
        ->and($first->old_price)->toBe('1400.00')
        ->and($first->stock_quantity)->toBe(2)
        ->and($second->price)->toBe('2300.00')
        ->and($second->stock_quantity)->toBe(5);
});

test('Stage 6 parent and explicit variant SKU remain independent after initial create', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create([
        'sku' => 'PARENT-SKU',
        'price' => 3100,
    ]);
    $variant = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'CHILD-SKU',
        'price' => 3100,
    ]);

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSet('data.sku', 'PARENT-SKU')
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.sku', 'PARENT-SKU-EDITED')
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.variants.record-'.$variant->getKey().'.sku', 'CHILD-SKU-EDITED')
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->refresh()->sku)->toBe('PARENT-SKU-EDITED')
        ->and($variant->refresh()->sku)->toBe('CHILD-SKU-EDITED')
        ->and($product->variants()->count())->toBe(1);
});

test('Stage 6 compact prices reject negative values', function () {
    [$category, $partType] = stage6ProductCatalog();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...stage6BaseProductData($category, $partType, 'stage-6-negative-prices'),
            'price' => -1,
            'old_price' => -2,
        ], 'form')
        ->call('create')
        ->assertHasFormErrors([
            'price' => 'min',
            'old_price' => 'min',
        ]);

    expect(Product::query()->where('slug', 'stage-6-negative-prices')->exists())->toBeFalse();
});

test('Stage 6 Generic normalizes stale option template part type and fitments while AutoPart rejects incompatible template', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $vehicle = stage6VehicleTree('generic-stale', 1);
    $autoTemplate = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'is_active' => true,
    ]);
    $genericTemplate = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_GENERIC,
        'is_active' => true,
    ]);

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-generic-stale'),
                'product_type' => ProductType::Generic->value,
                'part_type_id' => $partType->getKey(),
                'product_option_template_id' => $autoTemplate->getKey(),
                'fitments' => [[
                    'vehicle_make_id' => $vehicle['make']->getKey(),
                    'vehicle_model_id' => $vehicle['model']->getKey(),
                    'vehicle_generation_id' => $vehicle['generations'][0]->getKey(),
                    'note' => 'stale',
                    'is_primary' => true,
                ]],
            ], 'form')
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-incompatible-template'),
                'product_option_template_id' => $genericTemplate->getKey(),
            ], 'form')
            ->call('create')
            ->assertHasFormErrors(['product_option_template_id']);
    } finally {
        $undoRepeaterFake();
    }

    $generic = Product::query()->where('slug', 'stage-6-generic-stale')->firstOrFail();

    expect($generic->product_type)->toBe(ProductType::Generic)
        ->and($generic->part_type_id)->toBeNull()
        ->and($generic->product_option_template_id)->toBeNull()
        ->and($generic->fitments()->count())->toBe(0)
        ->and(Product::query()->where('slug', 'stage-6-incompatible-template')->exists())->toBeFalse();
});

test('Stage 6 product table renders the effective default variant price', function () {
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create(['price' => 1111]);
    ProductVariant::factory()->forProduct($product)->default()->create(['price' => 2222]);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet('price', '2222.00', $product);
});

test('Stage 6 failed explicit create validates options before processing pending gallery files', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->seed(ProductOptionSeeder::class);
    [$category, $partType] = stage6ProductCatalog();
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $group = ProductOptionGroup::query()->where('slug', 'profile')->firstOrFail();
    $value = ProductOptionValue::query()->whereBelongsTo($group, 'group')->firstOrFail();
    $variant = [
        'title' => 'Duplicate',
        'price' => 1000,
        'stock_quantity' => 1,
        'stock_status' => StockStatus::InStock->value,
        'is_default' => false,
        'is_active' => true,
        'options' => [],
        'variantOptionValues' => [[
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $value->getKey(),
        ]],
    ];

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-gallery-rollback-create'),
                'product_option_template_id' => $template->getKey(),
                'variants' => [
                    [...$variant, 'sku' => 'ROLLBACK-CREATE-A', 'is_default' => true],
                    [...$variant, 'sku' => 'ROLLBACK-CREATE-B'],
                ],
            ], 'form')
            ->set('data.gallery_uploads', [UploadedFile::fake()->image('rollback.jpg', 120, 90)])
            ->call('create')
            ->assertHasFormErrors(['variants']);
    } finally {
        $undoRepeaterFake();
    }

    expect(Product::query()->where('slug', 'stage-6-gallery-rollback-create')->exists())->toBeFalse()
        ->and(ProductImage::query()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles('uploads/products/pending/manual'))->toHaveCount(1)
        ->and(collect(Storage::disk('public')->allFiles('uploads/products'))
            ->every(fn (string $path): bool => str_starts_with($path, 'uploads/products/pending/manual/')))->toBeTrue();
});

test('Stage 6 gallery deletion rollback keeps the database row original and conversions', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->seed(ProductOptionSeeder::class);
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create();
    $group = ProductOptionGroup::query()->where('slug', 'profile')->firstOrFail();
    $value = ProductOptionValue::query()->whereBelongsTo($group, 'group')->firstOrFail();
    foreach (['ROLLBACK-EDIT-A', 'ROLLBACK-EDIT-B'] as $index => $sku) {
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'sku' => $sku,
            'is_default' => $index === 0,
        ]);
        $variant->variantOptionValues()->create([
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $value->getKey(),
        ]);
    }
    $path = 'uploads/products/'.$product->getKey().'/rollback.webp';
    $thumb = 'uploads/products/'.$product->getKey().'/conversions/rollback-thumb.webp';
    Storage::disk('public')->put($path, test_image_binary('webp'));
    Storage::disk('public')->put($thumb, test_image_binary('webp', 40, 30));
    $image = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => $path,
        'mime' => 'image/webp',
        'checksum' => str_repeat('d', 64),
        'conversions' => ['thumb' => ['disk' => 'public', 'path' => $thumb]],
    ]);

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.images', [])
            ->call('save')
            ->assertHasFormErrors(['variants']);
    } finally {
        $undoRepeaterFake();
    }

    expect($image->fresh())->toBeInstanceOf(ProductImage::class)
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->exists($thumb))->toBeTrue();
});

test('Stage 6 successful gallery deletion removes the database row and files after commit', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create();
    $path = 'uploads/products/'.$product->getKey().'/delete.webp';
    $thumb = 'uploads/products/'.$product->getKey().'/conversions/delete-thumb.webp';
    Storage::disk('public')->put($path, test_image_binary('webp'));
    Storage::disk('public')->put($thumb, test_image_binary('webp', 40, 30));
    $image = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => $path,
        'mime' => 'image/webp',
        'checksum' => str_repeat('e', 64),
        'conversions' => ['thumb' => ['disk' => 'public', 'path' => $thumb]],
    ]);

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.images', [])
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect(ProductImage::query()->whereKey($image)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists($path))->toBeFalse()
        ->and(Storage::disk('public')->exists($thumb))->toBeFalse();
});

test('Stage 6 one explicit variant remains repeater managed after reopening', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();

    try {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-one-explicit'),
                'sku' => 'ONE-EXPLICIT-PARENT',
                'price' => 9900,
                'old_price' => 10900,
                'default_stock_quantity' => 99,
                'variants' => [[
                    'sku' => 'ONE-EXPLICIT-CHILD',
                    'title' => 'Единственный явный',
                    'price' => 1200,
                    'old_price' => 1400,
                    'stock_quantity' => 2,
                    'stock_status' => StockStatus::InStock->value,
                    'is_default' => true,
                    'is_active' => true,
                    'options' => [],
                ]],
            ], 'form')
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('slug', 'stage-6-one-explicit')->firstOrFail();
        $variant = $product->defaultVariant()->firstOrFail();

        expect($variant->isTechnical())->toBeFalse()
            ->and($variant->price)->toBe('1200.00')
            ->and($product->price)->toBe('1200.00');

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.variants.record-'.$variant->getKey().'.sku', 'ONE-EXPLICIT-EDITED')
            ->set('data.variants.record-'.$variant->getKey().'.price', 1750)
            ->set('data.variants.record-'.$variant->getKey().'.old_price', 1950)
            ->set('data.variants.record-'.$variant->getKey().'.stock_quantity', 8)
            ->set('data.variants.record-'.$variant->getKey().'.stock_status', StockStatus::PreOrder->value)
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($variant->refresh()->isTechnical())->toBeFalse()
        ->and($variant->sku)->toBe('ONE-EXPLICIT-EDITED')
        ->and($variant->price)->toBe('1750.00')
        ->and($variant->old_price)->toBe('1950.00')
        ->and($variant->stock_quantity)->toBe(8)
        ->and($variant->stock_status)->toBe(StockStatus::PreOrder)
        ->and($product->refresh()->price)->toBe('1750.00')
        ->and($product->old_price)->toBe('1950.00')
        ->and($product->stock_status)->toBe(StockStatus::PreOrder);
});

test('Stage 6 remaining explicit variant does not become technical after deleting its sibling', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create(['price' => 9000]);
    $remaining = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'REMAINING-EXPLICIT',
        'price' => 2100,
        'stock_quantity' => 3,
    ]);
    ProductVariant::factory()->forProduct($product)->create([
        'sku' => 'DELETED-EXPLICIT',
        'price' => 3200,
    ]);

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.variants', [
                'record-'.$remaining->getKey() => [
                    'sku' => $remaining->sku,
                    'title' => $remaining->title,
                    'price' => $remaining->price,
                    'old_price' => $remaining->old_price,
                    'stock_quantity' => $remaining->stock_quantity,
                    'stock_status' => $remaining->stock_status->value,
                    'is_default' => true,
                    'is_active' => true,
                    'options' => [],
                    'variantOptionValues' => [],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->variants()->count())->toBe(1)
            ->and($remaining->refresh()->isTechnical())->toBeFalse();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.variants.record-'.$remaining->getKey().'.sku', 'REMAINING-EDITED')
            ->set('data.variants.record-'.$remaining->getKey().'.price', 2450)
            ->set('data.variants.record-'.$remaining->getKey().'.stock_quantity', 6)
            ->set('data.variants.record-'.$remaining->getKey().'.stock_status', StockStatus::OutOfStock->value)
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($remaining->refresh()->isTechnical())->toBeFalse()
        ->and($remaining->sku)->toBe('REMAINING-EDITED')
        ->and($remaining->price)->toBe('2450.00')
        ->and($remaining->stock_quantity)->toBe(6)
        ->and($remaining->stock_status)->toBe(StockStatus::OutOfStock)
        ->and($product->refresh()->price)->toBe('2450.00');
});

test('Stage 6 technical variant requires compact price and stays active default and idempotent', function () {
    [$category, $partType] = stage6ProductCatalog();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            ...stage6BaseProductData($category, $partType, 'stage-6-technical-marker'),
            'sku' => 'TECHNICAL-PARENT',
            'price' => 1500,
            'default_stock_quantity' => 4,
            'variants' => [],
        ], 'form')
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'stage-6-technical-marker')->firstOrFail();
    $variant = $product->defaultVariant()->firstOrFail();

    expect($variant->isTechnical())->toBeTrue();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.price', 1850)
        ->set('data.old_price', 2050)
        ->set('data.default_stock_quantity', 7)
        ->set('data.stock_status', StockStatus::PreOrder->value)
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.price', null)
        ->call('save')
        ->assertHasFormErrors(['price' => 'required']);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->variants()->count())->toBe(1)
        ->and($variant->refresh()->isTechnical())->toBeTrue()
        ->and($variant->price)->toBe('1850.00')
        ->and($variant->old_price)->toBe('2050.00')
        ->and($variant->stock_quantity)->toBe(7)
        ->and($variant->stock_status)->toBe(StockStatus::PreOrder)
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->is_active)->toBeTrue();
});

test('Stage 6 effective price table sorting uses default variant price with product fallback', function () {
    [$category, $partType] = stage6ProductCatalog();
    $high = Product::factory()->forCategory($category)->forPartType($partType)->create(['price' => 100]);
    ProductVariant::factory()->forProduct($high)->default()->create(['price' => 300]);
    $low = Product::factory()->forCategory($category)->forPartType($partType)->create(['price' => 900]);
    ProductVariant::factory()->forProduct($low)->default()->create(['price' => 100]);
    $fallback = Product::factory()->forCategory($category)->forPartType($partType)->create(['price' => 200]);

    Livewire::test(ListProducts::class)
        ->sortTable('price')
        ->assertCanSeeTableRecords([$low, $fallback, $high], inOrder: true);
});

test('Stage 6 option selections enforce group and template membership with full rollback', function () {
    $undoRepeaterFake = Repeater::fake();
    [$category, $partType] = stage6ProductCatalog();
    $template = ProductOptionTemplate::factory()->create();
    $allowedGroup = ProductOptionGroup::factory()->create();
    $allowedValue = ProductOptionValue::factory()->forGroup($allowedGroup)->create();
    $outsideGroup = ProductOptionGroup::factory()->create();
    $outsideValue = ProductOptionValue::factory()->forGroup($outsideGroup)->create();
    $outsideTemplateValue = ProductOptionValue::factory()->forGroup($allowedGroup)->create();
    $template->items()->create([
        'product_option_group_id' => $allowedGroup->getKey(),
        'product_option_value_id' => $allowedValue->getKey(),
        'position' => 0,
    ]);
    $cases = [
        'wrong-value-group' => [$allowedGroup, $outsideValue],
        'group-outside-template' => [$outsideGroup, $outsideValue],
        'value-outside-template' => [$allowedGroup, $outsideTemplateValue],
    ];

    try {
        foreach ($cases as $slug => [$group, $value]) {
            Livewire::test(CreateProduct::class)
                ->fillForm([
                    ...stage6BaseProductData($category, $partType, 'stage-6-'.$slug),
                    'product_option_template_id' => $template->getKey(),
                    'variants' => [[
                        'sku' => 'SKU-'.strtoupper($slug),
                        'title' => 'Tampered',
                        'price' => 1000,
                        'stock_quantity' => 1,
                        'stock_status' => StockStatus::InStock->value,
                        'is_default' => true,
                        'is_active' => true,
                        'options' => [],
                        'variantOptionValues' => [[
                            'product_option_group_id' => $group->getKey(),
                            'product_option_value_id' => $value->getKey(),
                        ]],
                    ]],
                ], 'form')
                ->call('create')
                ->assertHasFormErrors();

            expect(Product::query()->where('slug', 'stage-6-'.$slug)->exists())->toBeFalse();
        }

        Livewire::test(CreateProduct::class)
            ->fillForm([
                ...stage6BaseProductData($category, $partType, 'stage-6-valid-template-pair'),
                'product_option_template_id' => $template->getKey(),
                'variants' => [[
                    'sku' => 'STAGE6-VALID-TEMPLATE-PAIR',
                    'title' => 'Valid',
                    'price' => 1100,
                    'stock_quantity' => 2,
                    'stock_status' => StockStatus::InStock->value,
                    'is_default' => true,
                    'is_active' => true,
                    'options' => [],
                    'variantOptionValues' => [[
                        'product_option_group_id' => $allowedGroup->getKey(),
                        'product_option_value_id' => $allowedValue->getKey(),
                    ]],
                ]],
            ], 'form')
            ->call('create')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    $valid = Product::query()->where('slug', 'stage-6-valid-template-pair')->firstOrFail();

    expect($valid->variants()->count())->toBe(1)
        ->and($valid->defaultVariant()->firstOrFail()->optionValues()->pluck('product_option_values.id')->all())
        ->toBe([$allowedValue->getKey()]);
});

test('Stage 6 create compensates a partially processed gallery batch', function () {
    [$category, $partType] = stage6ProductCatalog();
    $firstPending = 'uploads/products/pending/manual/partial-first.jpg';
    $secondPending = 'uploads/products/pending/manual/partial-second.jpg';
    Storage::disk('public')->put($firstPending, test_image_binary('jpeg', 120, 90));
    Storage::disk('public')->put($secondPending, 'not-an-image');
    $component = Livewire::test(CreateProduct::class)
        ->fillForm(stage6BaseProductData($category, $partType, 'stage-6-partial-gallery-create'), 'form')
        ->set('data.gallery_uploads', [$firstPending, $secondPending]);

    expect(fn () => $component->call('create'))->toThrow(InvalidArgumentException::class);

    expect(Product::query()->where('slug', 'stage-6-partial-gallery-create')->exists())->toBeFalse()
        ->and(ProductImage::query()->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists($firstPending))->toBeFalse()
        ->and(Storage::disk('public')->exists($secondPending))->toBeTrue()
        ->and(collect(Storage::disk('public')->allFiles('uploads/products'))
            ->filter(fn (string $path): bool => ! str_starts_with($path, 'uploads/products/pending/manual/'))
            ->values()
            ->all())->toBe([]);
});

test('Stage 6 edit compensates new batch files without changing the existing gallery', function () {
    [$category, $partType] = stage6ProductCatalog();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create([
        'title' => 'Gallery before failure',
    ]);
    $existingPath = 'uploads/products/'.$product->getKey().'/existing.webp';
    $existingThumb = 'uploads/products/'.$product->getKey().'/conversions/existing-thumb.webp';
    Storage::disk('public')->put($existingPath, test_image_binary('webp', 120, 90));
    Storage::disk('public')->put($existingThumb, test_image_binary('webp', 40, 30));
    $existing = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => $existingPath,
        'mime' => 'image/webp',
        'checksum' => str_repeat('f', 64),
        'conversions' => ['thumb' => ['disk' => 'public', 'path' => $existingThumb]],
    ]);
    $firstPending = 'uploads/products/pending/manual/edit-partial-first.jpg';
    $secondPending = 'uploads/products/pending/manual/edit-partial-second.jpg';
    Storage::disk('public')->put($firstPending, test_image_binary('jpeg', 100, 80));
    Storage::disk('public')->put($secondPending, 'not-an-image');
    $component = Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.title', 'Gallery after failure')
        ->set('data.gallery_uploads', [$firstPending, $secondPending]);

    expect(fn () => $component->call('save'))->toThrow(InvalidArgumentException::class);

    expect($product->refresh()->title)->toBe('Gallery before failure')
        ->and($product->images()->count())->toBe(1)
        ->and($existing->fresh())->toBeInstanceOf(ProductImage::class)
        ->and($existing->is_main)->toBeTrue()
        ->and($existing->is_visible)->toBeTrue()
        ->and($existing->path)->toBe($existingPath)
        ->and($existing->conversions)->toBe(['thumb' => ['disk' => 'public', 'path' => $existingThumb]])
        ->and(Storage::disk('public')->exists($existingPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($existingThumb))->toBeTrue()
        ->and(Storage::disk('public')->exists($firstPending))->toBeFalse()
        ->and(Storage::disk('public')->exists($secondPending))->toBeTrue()
        ->and(collect(Storage::disk('public')->allFiles('uploads/products/'.$product->getKey()))
            ->sort()
            ->values()
            ->all())->toBe(collect([$existingPath, $existingThumb])->sort()->values()->all());
});
