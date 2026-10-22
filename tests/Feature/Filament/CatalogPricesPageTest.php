<?php

use App\Filament\Pages\CatalogPricesPage;
use App\Livewire\CatalogActionsPanel;
use App\Models\CatalogConfiguration;
use App\Models\PartType;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogActions;
use App\Services\Catalog\CatalogConfigurations;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    $this->actingAs($this->admin = User::factory()->superAdmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    app(CatalogConfigurations::class)->install(base_path('tests/Fixtures/catalog-configurations.csv'));
});

function managementParameters(Product $product, array $extra = []): array
{
    return array_replace([
        'kind' => 'price', 'type_id' => $product->part_type_id, 'source_status' => 'all',
        'descendants' => false, 'target_status' => 'archived', 'force' => false,
        'configuration_id' => $product->variants()->where('is_active', true)->value('catalog_configuration_id'),
        'price' => '3333.00', 'search' => '',
    ], $extra);
}

function managedProduct(): Product
{
    $type = PartType::where('full_slug', 'usilitel')->firstOrFail();
    $product = Product::factory()->forPartType($type)->create([
        'product_option_template_id' => app(CatalogConfigurations::class)->template($type->id)->id,
        'status' => 'active', 'price_mode' => 'inherited',
    ]);
    app(CatalogConfigurations::class)->synchronize($product);

    return $product->fresh();
}

function completeManagementOperation(int $id): void
{
    $actions = app(CatalogActions::class);
    while (in_array(($op = DB::table('catalog_actions')->find($id))->status, ['pending', 'running'], true)) {
        $actions->batch($id, (int) $op->last_id);
    }
}

test('mass management page displays only statuses and prices', function () {
    Livewire::test(CatalogPricesPage::class)
        ->assertSee('Массовое управление товарами')
        ->assertSee('Изменить статус товаров')
        ->assertSee('Изменить цены')
        ->assertDontSee('Принять исторические цены');
});

test('manual price changes only the chosen configuration and the next import restores CSV price', function () {
    $product = managedProduct();
    $params = managementParameters($product);
    $configuration = CatalogConfiguration::findOrFail($params['configuration_id']);
    $variant = $product->variants()->where('catalog_configuration_id', $configuration->id)->firstOrFail();
    $other = $product->variants()->where('catalog_configuration_id', '!=', $configuration->id)->firstOrFail();
    $original = $variant->price;
    $otherPrice = $other->price;
    $base = $product->price;

    $actions = app(CatalogActions::class);
    $preview = $actions->preview($this->admin, $params);
    expect($preview['counts']['changed'])->toBe(1)->and($preview['counts']['prices_to_change'])->toBe(1);
    $id = $actions->confirm($this->admin, $preview);
    completeManagementOperation($id);

    expect($variant->fresh()->price)->toBe('3333.00')
        ->and($variant->fresh()->price_mode)->toBe('individual')
        ->and($other->fresh()->price)->toBe($otherPrice)
        ->and($product->fresh()->price)->toBe($variant->is_default ? '3333.00' : $base)
        ->and(DB::table('catalog_matrix_price_history')->where('product_variant_id', $variant->id)->count())->toBe(1);

    app(CatalogConfigurations::class)->synchronize($product->fresh(), 'import:99');
    expect($variant->fresh()->price)->toBe($original)
        ->and($variant->fresh()->price_mode)->toBe('inherited')
        ->and($other->fresh()->price)->toBe($otherPrice)
        ->and(DB::table('catalog_matrix_price_history')->where('product_variant_id', $variant->id)->count())->toBe(2);
});

test('search and status constrain the mass price operation and prevent stale confirmation', function () {
    $product = managedProduct();
    managedProduct();
    $actions = app(CatalogActions::class);
    $params = managementParameters($product, ['search' => (string) $product->id]);
    $preview = $actions->preview($this->admin, $params);
    expect($preview['counts']['products'])->toBe(1);
    DB::table('product_variants')->where('product_id', $product->id)
        ->where('catalog_configuration_id', $params['configuration_id'])->update(['price' => '9999.00']);
    expect(fn () => $actions->confirm($this->admin, $preview))->toThrow(ValidationException::class);
    expect(DB::table('catalog_actions')->count())->toBe(0);
});

test('price operation rejects a combination from another part type', function () {
    $product = managedProduct();
    $foreign = CatalogConfiguration::where('part_type_id', '!=', $product->part_type_id)->firstOrFail();
    expect(fn () => app(CatalogActions::class)->preview($this->admin,
        managementParameters($product, ['configuration_id' => $foreign->id])))
        ->toThrow(ValidationException::class);
});

test('price editor retains explicit review and confirmation and does not write on preview', function () {
    $product = managedProduct();
    $configurationId = $product->variants()->where('is_active', true)->value('catalog_configuration_id');
    $page = Livewire::test(CatalogActionsPanel::class)
        ->set('parameters.kind', 'price')
        ->set('parameters.type_id', (string) $product->part_type_id)
        ->set('parameters.configuration_id', (string) $configurationId)
        ->set('parameters.price', '3456')
        ->call('calculate')->assertHasNoErrors()
        ->assertSet('preview.counts.products', 1);
    expect(DB::table('catalog_actions')->count())->toBe(0);
    $page->call('confirm')->assertHasNoErrors();
    completeManagementOperation($page->get('operationId'));
    expect($product->variants()->where('catalog_configuration_id', $configurationId)->value('price'))->toBe('3456.00');
});
