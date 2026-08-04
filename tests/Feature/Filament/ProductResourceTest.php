<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCharacteristic;
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

/** @return array<string, mixed> */
function productOptionHardeningData(
    ProductCategory $category,
    PartType $partType,
    string $slug,
    ?ProductOptionTemplate $template = null,
): array {
    return [
        'product_type' => ProductType::AutoPart->value,
        'title' => 'Товар '.$slug,
        'slug' => $slug,
        'product_category_id' => $category->getKey(),
        'part_type_id' => $partType->getKey(),
        'product_option_template_id' => $template?->getKey(),
        'status' => ProductStatus::Active->value,
        'position' => 0,
        'is_featured' => false,
        'price' => 1000,
        'stock_status' => StockStatus::InStock->value,
    ];
}

test('ProductResource limits option selectors to the selected template', function () {
    $this->seed(ProductOptionSeeder::class);

    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $profile = ProductOptionGroup::query()->where('slug', 'profile')->firstOrFail();
    $full = ProductOptionValue::query()
        ->whereBelongsTo($profile, 'group')
        ->where('slug', 'full')
        ->firstOrFail();
    $outsideGroup = ProductOptionGroup::factory()->create();
    $outsideValue = ProductOptionValue::factory()->forGroup($profile)->create();

    expect(ProductForm::optionGroupOptions($template->getKey()))
        ->toHaveKey($profile->getKey())
        ->not->toHaveKey($outsideGroup->getKey())
        ->and(ProductForm::optionValueOptions($profile->getKey(), $template->getKey()))
        ->toHaveKey($full->getKey())
        ->not->toHaveKey($outsideValue->getKey());
});

test('ProductForm relation options reject forged state without unsafe query bindings', function (): void {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    $template = ProductOptionTemplate::factory()->create(['part_type_id' => $partType->getKey()]);

    expect(ProductForm::productCategoryOptions([]))->toBe([])
        ->and(ProductForm::partTypeOptions('1abc'))->toBe([])
        ->and(ProductForm::vehicleMakeOptions([]))->toBe([])
        ->and(ProductForm::vehicleModelOptions([], null))->toBe([])
        ->and(ProductForm::vehicleGenerationOptions('1abc', null))->toBe([])
        ->and(ProductForm::optionTemplateOptions(ProductType::AutoPart, [], null))->toBe([])
        ->and(ProductForm::productCategoryOptions((string) $category->getKey()))->toHaveKey($category->getKey())
        ->and(ProductForm::partTypeOptions((string) $partType->getKey()))->toHaveKey($partType->getKey())
        ->and(ProductForm::vehicleMakeOptions((string) $make->getKey()))->toHaveKey($make->getKey())
        ->and(ProductForm::vehicleModelOptions((string) $make->getKey(), (string) $model->getKey()))->toHaveKey($model->getKey())
        ->and(ProductForm::vehicleGenerationOptions((string) $model->getKey(), (string) $generation->getKey()))->toHaveKey($generation->getKey())
        ->and(ProductForm::optionTemplateOptions(
            ProductType::AutoPart,
            (string) $partType->getKey(),
            (string) $template->getKey(),
        ))->toHaveKey($template->getKey());
});

test('ProductResource forged relation state returns validation without changing the product', function (string $path, mixed $value): void {
    $undoRepeaterFake = Repeater::fake();
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    $template = ProductOptionTemplate::factory()->create(['part_type_id' => $partType->getKey()]);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->withDefaultVariant()
        ->create([
            'title' => 'Неизменяемый товар forged state',
            'product_option_template_id' => $template->getKey(),
        ]);
    $value = is_callable($value) ? $value($make, $model, $generation) : $value;

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set($path, $value)
            ->call('save')
            ->assertStatus(200)
            ->assertHasFormErrors();

        expect($product->refresh()->title)->toBe('Неизменяемый товар forged state')
            ->and($product->part_type_id)->toBe($partType->getKey())
            ->and($product->product_option_template_id)->toBe($template->getKey())
            ->and($product->fitments()->count())->toBe(0);
    } finally {
        $undoRepeaterFake();
    }
})->with([
    'part type array' => ['data.part_type_id', []],
    'part type mixed string' => ['data.part_type_id', '1abc'],
    'template array' => ['data.product_option_template_id', []],
    'vehicle make array' => ['data.fitments', fn (VehicleMake $make, VehicleModel $model, VehicleGeneration $generation): array => [[
        'vehicle_make_id' => [],
        'vehicle_model_id' => $model->getKey(),
        'vehicle_generation_id' => $generation->getKey(),
        'note' => null,
        'is_primary' => false,
    ]]],
    'vehicle model mixed string' => ['data.fitments', fn (VehicleMake $make, VehicleModel $model, VehicleGeneration $generation): array => [[
        'vehicle_make_id' => $make->getKey(),
        'vehicle_model_id' => '1abc',
        'vehicle_generation_id' => $generation->getKey(),
        'note' => null,
        'is_primary' => false,
    ]]],
]);

test('ProductResource preserves inactive assigned options and labels them on an ordinary save', function (): void {
    $undoRepeaterFake = Repeater::fake();
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $group = ProductOptionGroup::factory()->create(['is_active' => true]);
    $value = ProductOptionValue::factory()->forGroup($group)->create(['is_active' => true]);
    $template = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'is_active' => true,
    ]);
    $template->items()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
        'position' => 10,
    ]);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->withDefaultVariant()
        ->create(['product_option_template_id' => $template->getKey()]);
    $variant = $product->defaultVariant()->firstOrFail();
    $selection = $variant->variantOptionValues()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
    ]);
    $template->update(['is_active' => false]);
    $group->update(['is_active' => false]);
    $value->update(['is_active' => false]);

    expect(ProductForm::optionTemplateOptions($product->product_type, $partType->getKey()))
        ->not->toHaveKey($template->getKey())
        ->and(ProductForm::optionGroupOptions($template->getKey()))
        ->not->toHaveKey($group->getKey())
        ->and(ProductForm::optionValueOptions($group->getKey(), $template->getKey()))
        ->not->toHaveKey($value->getKey())
        ->and(ProductForm::optionTemplateOptions(
            $product->product_type,
            $partType->getKey(),
            $template->getKey(),
        ))
        ->toHaveKey($template->getKey(), $template->title.' (Неактивно)')
        ->and(ProductForm::optionGroupOptions($template->getKey(), $group->getKey()))
        ->toHaveKey($group->getKey(), $group->title.' (Неактивно)')
        ->and(ProductForm::optionValueOptions($group->getKey(), $template->getKey(), $value->getKey()))
        ->toHaveKey($value->getKey(), $value->title.' (Неактивно)');

    try {
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->refresh()->product_option_template_id)->toBe($template->getKey())
        ->and($selection->refresh()->exists)->toBeTrue()
        ->and($selection->product_option_value_id)->toBe($value->getKey());
});

test('ProductResource filters templates by part type while retaining the selected historical template', function (): void {
    $category = productResourceCategory();
    $firstPartType = productResourcePartType($category);
    $secondPartType = PartType::factory()->forCategory($category)->create();
    $global = ProductOptionTemplate::factory()->create(['part_type_id' => null]);
    $specific = ProductOptionTemplate::factory()->create(['part_type_id' => $firstPartType->getKey()]);
    $otherSpecific = ProductOptionTemplate::factory()->create(['part_type_id' => $secondPartType->getKey()]);
    $inactiveHistorical = ProductOptionTemplate::factory()->create([
        'part_type_id' => $secondPartType->getKey(),
        'is_active' => false,
    ]);

    expect(ProductForm::optionTemplateOptions(ProductType::AutoPart, $firstPartType->getKey()))
        ->toHaveKeys([$global->getKey(), $specific->getKey()])
        ->not->toHaveKeys([$otherSpecific->getKey(), $inactiveHistorical->getKey()])
        ->and(ProductForm::optionTemplateOptions(
            ProductType::AutoPart,
            $firstPartType->getKey(),
            $inactiveHistorical->getKey(),
        ))
        ->toHaveKey($inactiveHistorical->getKey(), $inactiveHistorical->title.' (Неактивно)');
});

test('CreateProduct reactively assigns a specific default after part type selection', function (): void {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $globalDefault = ProductOptionTemplate::factory()->default()->create();
    $specificDefault = ProductOptionTemplate::factory()->default()->create([
        'part_type_id' => $partType->getKey(),
    ]);

    Livewire::test(CreateProduct::class)
        ->assertSet('data.product_option_template_id', null)
        ->set('data.part_type_id', $partType->getKey())
        ->assertSet('data.product_option_template_id', $specificDefault->getKey())
        ->assertSet('data.product_option_template_id', fn (mixed $state): bool => (int) $state !== (int) $globalDefault->getKey());
});

test('CreateProduct reactively uses the global fallback without overwriting a manual selection', function (): void {
    $category = productResourceCategory();
    $firstPartType = productResourcePartType($category);
    $secondPartType = PartType::factory()->forCategory($category)->create();
    $globalDefault = ProductOptionTemplate::factory()->default()->create();
    $manual = ProductOptionTemplate::factory()->create();

    Livewire::test(CreateProduct::class)
        ->assertSet('data.product_option_template_id', null)
        ->set('data.part_type_id', $firstPartType->getKey())
        ->assertSet('data.product_option_template_id', $globalDefault->getKey())
        ->set('data.product_option_template_id', $manual->getKey())
        ->set('data.part_type_id', $secondPartType->getKey())
        ->assertSet('data.product_option_template_id', $manual->getKey())
        ->set('data.product_type', ProductType::Generic->value)
        ->assertSet('data.product_option_template_id', null)
        ->set('data.product_type', ProductType::AutoPart->value)
        ->assertSet('data.product_option_template_id', $globalDefault->getKey());
});

test('ProductResource rejects forged inactive and incompatible template assignments before create', function (): void {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $otherPartType = PartType::factory()->forCategory($category)->create();
    $inactive = ProductOptionTemplate::factory()->create(['is_active' => false]);
    $wrongPartType = ProductOptionTemplate::factory()->create([
        'part_type_id' => $otherPartType->getKey(),
    ]);

    foreach ([
        'inactive-template-forged' => $inactive,
        'wrong-part-template-forged' => $wrongPartType,
    ] as $slug => $template) {
        Livewire::test(CreateProduct::class)
            ->fillForm(productOptionHardeningData($category, $partType, $slug, $template), 'form')
            ->call('create')
            ->assertHasFormErrors(['product_option_template_id']);

        expect(Product::query()->where('slug', $slug)->exists())->toBeFalse();
    }
});

test('ProductResource preserves only the assigned inactive template and rejects another inactive template', function (): void {
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $assigned = ProductOptionTemplate::factory()->create(['is_active' => true]);
    $other = ProductOptionTemplate::factory()->create(['is_active' => false]);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->withDefaultVariant()
        ->create(['product_option_template_id' => $assigned->getKey()]);
    $assigned->update(['is_active' => false]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.product_option_template_id', $other->getKey())
        ->call('save')
        ->assertHasFormErrors(['product_option_template_id']);

    expect($product->refresh()->product_option_template_id)->toBe($assigned->getKey());
});

test('ProductResource rejects a selected template after part type becomes incompatible and accepts a compatible one', function (): void {
    $category = productResourceCategory();
    $firstPartType = productResourcePartType($category);
    $secondPartType = PartType::factory()->forCategory($category)->create();
    $specific = ProductOptionTemplate::factory()->create([
        'part_type_id' => $firstPartType->getKey(),
    ]);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($firstPartType)
        ->withDefaultVariant()
        ->create(['product_option_template_id' => $specific->getKey()]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.part_type_id', $secondPartType->getKey())
        ->assertSet('data.product_option_template_id', $specific->getKey())
        ->call('save')
        ->assertHasFormErrors(['product_option_template_id']);

    expect($product->refresh()->part_type_id)->toBe($firstPartType->getKey())
        ->and($product->product_option_template_id)->toBe($specific->getKey());

    $compatible = ProductOptionTemplate::factory()->create([
        'part_type_id' => $firstPartType->getKey(),
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->set('data.product_option_template_id', $compatible->getKey())
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->product_option_template_id)->toBe($compatible->getKey());
});

test('ProductResource preserves a historical option pair removed from the current template', function (): void {
    $undoRepeaterFake = Repeater::fake();
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $group = ProductOptionGroup::factory()->create(['title' => 'Историческая группа']);
    $value = ProductOptionValue::factory()->forGroup($group)->create(['title' => 'Историческое значение']);
    $template = ProductOptionTemplate::factory()->create();
    $item = $template->items()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
        'position' => 10,
    ]);
    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->withDefaultVariant()
        ->create(['product_option_template_id' => $template->getKey()]);
    $variant = $product->defaultVariant()->firstOrFail();
    $selection = $variant->variantOptionValues()->create([
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
    ]);
    $variant->syncOptionsSnapshotFromValues();
    $snapshot = $variant->refresh()->options;
    $item->delete();

    expect(ProductForm::optionGroupOptions($template->getKey()))
        ->not->toHaveKey($group->getKey())
        ->and(ProductForm::optionValueOptions($group->getKey(), $template->getKey()))
        ->not->toHaveKey($value->getKey())
        ->and(ProductForm::optionGroupOptions($template->getKey(), $group->getKey()))
        ->toHaveKey($group->getKey(), $group->title)
        ->and(ProductForm::optionValueOptions($group->getKey(), $template->getKey(), $value->getKey()))
        ->toHaveKey($value->getKey(), $value->title);

    try {
        $component = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
        $variantStates = array_values($component->get('data')['variants']);
        $selectionStates = array_values($variantStates[0]['variantOptionValues']);

        expect((int) $selectionStates[0]['product_option_group_id'])->toBe((int) $group->getKey())
            ->and((int) $selectionStates[0]['product_option_value_id'])->toBe((int) $value->getKey());

        $component
            ->call('save')
            ->assertHasNoFormErrors();

        expect($selection->refresh()->product_option_group_id)->toBe($group->getKey())
            ->and($selection->product_option_value_id)->toBe($value->getKey())
            ->and($variant->refresh()->options)->toBe($snapshot);

        $variantStates = array_values($component->get('data')['variants']);
        $variantStates[] = [
            'sku' => 'FORGED-HISTORICAL-PAIR',
            'title' => 'Новый вариант с исторической парой',
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

        $component
            ->set('data.variants', $variantStates)
            ->call('save')
            ->assertHasFormErrors();
    } finally {
        $undoRepeaterFake();
    }

    expect($product->variants()->count())->toBe(1)
        ->and(ProductVariant::query()->where('sku', 'FORGED-HISTORICAL-PAIR')->exists())->toBeFalse()
        ->and($selection->refresh()->exists)->toBeTrue()
        ->and($variant->refresh()->options)->toBe($snapshot);
});

test('ProductResource rejects forged inactive groups and values for new variants atomically', function (): void {
    $undoRepeaterFake = Repeater::fake();
    $category = productResourceCategory();
    $partType = productResourcePartType($category);

    try {
        foreach ([
            'inactive-group' => [false, true],
            'inactive-value' => [true, false],
        ] as $slug => [$groupActive, $valueActive]) {
            $group = ProductOptionGroup::factory()->create(['is_active' => $groupActive]);
            $value = ProductOptionValue::factory()->forGroup($group)->create(['is_active' => $valueActive]);
            $template = ProductOptionTemplate::factory()->create();
            $template->items()->create([
                'product_option_group_id' => $group->getKey(),
                'product_option_value_id' => $value->getKey(),
                'position' => 0,
            ]);

            Livewire::test(CreateProduct::class)
                ->fillForm([
                    ...productOptionHardeningData($category, $partType, $slug, $template),
                    'variants' => [[
                        'sku' => strtoupper($slug),
                        'title' => 'Подделанный вариант',
                        'price' => 1000,
                        'stock_quantity' => 1,
                        'stock_status' => StockStatus::InStock->value,
                        'is_default' => true,
                        'is_active' => true,
                        'options' => ['legacy' => 'не сохранять'],
                        'variantOptionValues' => [[
                            'product_option_group_id' => $group->getKey(),
                            'product_option_value_id' => $value->getKey(),
                        ]],
                    ]],
                ], 'form')
                ->call('create')
                ->assertHasFormErrors();

            expect(Product::query()->where('slug', $slug)->exists())->toBeFalse()
                ->and(ProductVariant::query()->where('sku', strtoupper($slug))->exists())->toBeFalse();
        }
    } finally {
        $undoRepeaterFake();
    }
});

test('ProductResource SEO tab validates canonical URL and saves extended metadata', function () {
    $product = Product::factory()->withDefaultVariant()->create([
        'product_type' => ProductType::Generic,
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertSchemaComponentExists('product-seo-tab')
        ->assertFormFieldExists('seo_h1')
        ->assertFormFieldExists('seo_text')
        ->assertFormFieldExists('og_image')
        ->fillForm(['canonical_url' => 'invalid-url'])
        ->call('save')
        ->assertHasFormErrors(['canonical_url' => 'url'])
        ->fillForm([
            'meta_title' => 'SEO title товара',
            'meta_description' => 'SEO description товара',
            'seo_h1' => 'SEO H1 товара',
            'seo_text' => 'Расширенный SEO-текст товара',
            'canonical_url' => 'https://example.test/products/canonical',
            'noindex' => true,
            'og_title' => 'OG title товара',
            'og_description' => 'OG description товара',
            'og_image' => UploadedFile::fake()->image('product-og.jpg', 1200, 630),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh())
        ->meta_title->toBe('SEO title товара')
        ->seo_h1->toBe('SEO H1 товара')
        ->seo_text->toBe('Расширенный SEO-текст товара')
        ->canonical_url->toBe('https://example.test/products/canonical')
        ->noindex->toBeTrue()
        ->og_title->toBe('OG title товара')
        ->og_description->toBe('OG description товара')
        ->og_image->not->toBeNull();

    Storage::disk('public')->assertExists($product->og_image);
});

test('ProductResource saves option template normalized variant values and characteristics', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->seed(ProductOptionSeeder::class);
    $category = productResourceCategory();
    $partType = productResourcePartType($category);
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $profile = ProductOptionGroup::query()->where('slug', 'profile')->firstOrFail();
    $full = ProductOptionValue::query()
        ->where('product_option_group_id', $profile->getKey())
        ->where('slug', 'full')
        ->firstOrFail();

    try {
        Livewire::test(CreateProduct::class)
            ->assertFormFieldExists('product_option_template_id', fn (Select $field): bool => $field->getLabel() === 'Шаблон опций')
            ->assertSchemaComponentExists('product-characteristics-tab')
            ->fillForm([
                'product_type' => ProductType::AutoPart->value,
                'title' => 'Порог с управляемыми опциями',
                'slug' => 'porog-managed-options',
                'product_category_id' => $category->getKey(),
                'part_type_id' => $partType->getKey(),
                'product_option_template_id' => $template->getKey(),
                'status' => ProductStatus::Active->value,
                'position' => 0,
                'stock_status' => StockStatus::InStock->value,
                'variants' => [[
                    'sku' => 'MANAGED-OPTION-BASE',
                    'title' => 'Основной вариант',
                    'price' => 8900,
                    'stock_quantity' => 4,
                    'stock_status' => StockStatus::InStock->value,
                    'is_default' => true,
                    'is_active' => true,
                    'options' => [],
                    'variantOptionValues' => [[
                        'product_option_group_id' => $profile->getKey(),
                        'product_option_value_id' => $full->getKey(),
                    ]],
                ]],
                'characteristics' => [[
                    'name' => 'Производство',
                    'value' => 'Россия',
                    'unit' => null,
                    'source_type' => ProductCharacteristic::SOURCE_MANUAL,
                    'is_visible' => true,
                    'position' => 10,
                ]],
            ], 'form')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();
    } finally {
        $undoRepeaterFake();
    }

    $product = Product::query()->where('slug', 'porog-managed-options')->firstOrFail();
    $variant = $product->variants()->firstOrFail();

    expect($product->optionTemplate->is($template))->toBeTrue()
        ->and($variant->optionValues()->pluck('product_option_values.id')->all())->toBe([$full->getKey()])
        ->and($variant->optionSummary())->toBe('Профиль: Полный')
        ->and($variant->options)->toBe([
            'profile' => ['group' => 'Профиль', 'value' => 'Полный'],
        ])
        ->and($product->characteristics()->count())->toBe(1)
        ->and($product->characteristics()->firstOrFail()->name)->toBe('Производство');
});

test('ProductResource generic product can remain without an option template', function () {
    $this->seed(ProductOptionSeeder::class);

    Livewire::test(CreateProduct::class)
        ->set('data.product_type', ProductType::Generic->value)
        ->assertSet('data.product_option_template_id', null)
        ->assertSchemaComponentExists('product-characteristics-tab');
});

test('ProductResource exposes an explicit action for bounded template variant generation', function () {
    $this->seed(ProductOptionSeeder::class);
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $product = Product::factory()->withDefaultVariant()->create([
        'product_option_template_id' => $template->getKey(),
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionExists('generate_variants_from_template')
        ->callAction('generate_variants_from_template')
        ->assertNotified();

    expect($product->variants()->count())->toBe(24)
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

test('auto part exposes part type and cars tab while a temporary generic switch preserves hidden state', function () {
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
        ->assertSet('data.part_type_id', $partType->getKey())
        ->assertSet('data.fitments', [])
        ->set('data.product_type', ProductType::AutoPart->value)
        ->assertFormFieldVisible('part_type_id')
        ->assertSchemaComponentVisible('product-fitments-tab')
        ->assertSet('data.part_type_id', $partType->getKey());
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
        ->and($variant->isTechnical())->toBeTrue()
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
        ->create(['sku' => 'PARENT-DEFAULT-SKU']);
    $variant = $product->defaultVariant()->firstOrFail();
    $variant->forceFill([
        'sku' => 'MANUAL-DEFAULT-SKU',
        'options' => ProductVariant::technicalOptions(),
        'price' => 17850,
        'old_price' => 18900,
        'stock_quantity' => 4,
        'stock_status' => StockStatus::PreOrder,
    ])->save();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertSet('data.sku', 'PARENT-DEFAULT-SKU')
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
        ->and($product->refresh()->sku)->toBe('PARENT-DEFAULT-SKU')
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
