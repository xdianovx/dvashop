<?php

use App\Livewire\CatalogActionsPanel;
use App\Models\Cart;
use App\Models\CatalogConfiguration;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PartType;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CartManager;
use App\Services\Catalog\CatalogActions;
use App\Services\Catalog\CatalogBulkPrices;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\Feeds\YandexFeedGenerator;
use App\Services\Feeds\YandexFeedState;
use App\Services\StorefrontProductAvailability;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    $this->admin = User::factory()->superAdmin()->create();
    $this->matrix = app(CatalogConfigurations::class);
    $this->matrix->install(base_path('tests/Fixtures/catalog-configurations.csv'));
});

function matrixProduct(string $slug): Product
{
    $type = PartType::where('full_slug', $slug)->firstOrFail();

    return Product::factory()->forPartType($type)->create(['product_option_template_id' => app(CatalogConfigurations::class)->template($type->id)->id, 'status' => 'active', 'price_mode' => 'inherited']);
}

function matrixParameters(Product $product, string $kind = 'status', array $extra = []): array
{
    return array_replace(['kind' => $kind, 'type_id' => $product->part_type_id, 'descendants' => false,
        'source_status' => 'all', 'target_status' => 'archived', 'force' => false, 'configuration_id' => null, 'price' => null], $extra);
}

function finishCatalogAction(int $id): void
{
    while (in_array(($op = DB::table('catalog_actions')->find($id))->status, ['pending', 'running'], true)) {
        app(CatalogActions::class)->batch($id, (int) $op->last_id);
    }
}

test('all 106 CSV rows retain exact options and decimal prices without Cartesian expansion', function () {
    $rows = app(CatalogConfigurationSource::class)->read(base_path('tests/Fixtures/catalog-configurations.csv'));
    expect($rows)->toHaveCount(106)->and(collect($rows)->pluck('group')->unique())->toHaveCount(14);
    foreach (collect($rows)->groupBy('type') as $slug => $expected) {
        $product = matrixProduct($slug);
        DB::transaction(fn () => $this->matrix->synchronize($product));
        $variants = $product->variants()->where('is_active', true)->with('optionValues.group')->get();
        expect($variants)->toHaveCount($expected->count());
        foreach ($expected as $row) {
            $variant = $variants->first(fn ($v) => $this->matrix->signature($v) === $row['signature']);
            expect($variant)->not->toBeNull()->and($variant->price)->toBe($row['price']);
        }
    }
    $arch = matrixProduct('arka/vnutrenniaia-universalnaia');
    DB::transaction(fn () => $this->matrix->synchronize($arch));
    expect($arch->variants()->whereHas('optionValues.group', fn ($q) => $q->where('code', 'profile'))->exists())->toBeFalse();
    expect($this->matrix->rows(PartType::where('full_slug', 'arka/karman-zadniaia')->value('id')))->toBeEmpty();
});

test('matrix synchronization preserves matching IDs custom amounts and deactivates legacy variants without deleting', function () {
    $product = matrixProduct('porog');
    $legacy = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 999, 'price_mode' => 'individual']);
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $ids = $product->variants()->where('is_active', true)->pluck('id')->all();
    $custom = ProductVariant::findOrFail($ids[0]);
    $custom->update(['price' => '777.00']);
    DB::transaction(fn () => $this->matrix->synchronize($product->refresh()));
    expect($legacy->refresh()->is_active)->toBeFalse()->and($legacy->price)->toBe('999.00')
        ->and($custom->refresh()->price)->toBe('777.00')->and($product->variants()->where('is_active', true)->pluck('id')->all())->toBe($ids);
    expect(app(StorefrontProductAvailability::class)->isPurchasable($legacy))->toBeFalse();
    $this->post(route('cart.items.store'), ['product_variant_id' => $legacy->id, 'quantity' => 1])->assertSessionHasErrors('product_variant_id');
});

test('single configuration price updates inherited variants only and repeated installation preserves administrative prices', function () {
    $product = matrixProduct('usilitel');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $variant = $product->variants()->where('is_active', true)->firstOrFail();
    $action = app(CatalogActions::class);
    $preview = $action->preview($this->admin, matrixParameters($product, 'configuration_price', ['configuration_id' => $variant->catalog_configuration_id, 'price' => '1234.50']));
    $id = $action->confirm($this->admin, $preview);
    finishCatalogAction($id);
    expect($variant->refresh()->price)->toBe('1234.50');
    $source = base_path('tests/Fixtures/catalog-configurations.csv');
    expect(fn () => $this->matrix->install($source))->toThrow(ValidationException::class);
    $review = app(\App\Services\Catalog\CatalogConfigurationInstallation::class)->preview(app(CatalogConfigurationSource::class)->read($source));
    $this->matrix->install($source, $review['confirmation']);
    expect(CatalogConfiguration::find($variant->catalog_configuration_id)->price)->toBe('1234.50');
    expect(fn () => app(CatalogBulkPrices::class)->preview($product->part_type_id, false, '100'))->toThrow(ValidationException::class);
    $variant->update(['price' => '999.00']);
    $id = $action->confirm($this->admin, $action->preview($this->admin, matrixParameters($product, 'configuration_price', ['configuration_id' => $variant->catalog_configuration_id, 'price' => '2000'])));
    finishCatalogAction($id);
    expect($variant->refresh()->price)->toBe('999.00');
});

test('statuses are authorized idempotent and preserve prices variants and publication exclusions', function () {
    $product = matrixProduct('usilitel');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $before = $product->variants()->get()->toArray();
    $price = $product->price;
    $action = app(CatalogActions::class);
    $preview = $action->preview($this->admin, matrixParameters($product));
    $id = $action->confirm($this->admin, $preview);
    finishCatalogAction($id);
    expect($action->confirm($this->admin, $preview))->toBe($id)
        ->and($product->refresh()->status->value)->toBe('archived')->and($product->publication_override)->toBe('archived')
        ->and($product->price)->toBe($price)->and($product->variants()->get()->toArray())->toBe($before);
    $product->forceFill(['import_source' => 'catalog', 'import_present' => false])->save();
    $p = matrixParameters($product, extra: ['target_status' => 'active']);
    expect($action->preview($this->admin, $p)['counts']['protected'])->toBe(1);
    $id = $action->confirm($this->admin, $action->preview($this->admin, $p + []));
    finishCatalogAction($id);
    expect($product->refresh()->status->value)->toBe('archived');
    $p['force'] = true;
    $id = $action->confirm($this->admin, $action->preview($this->admin, $p));
    finishCatalogAction($id);
    expect($product->refresh()->status->value)->toBe('active')->and($product->publication_override)->toBe('force_active');
});

test('concurrent edits and a failed batch never advance cursor and can be resumed without duplicate effects', function () {
    $product = matrixProduct('usilitel');
    $service = app(CatalogActions::class);
    $preview = $service->preview($this->admin, matrixParameters($product));
    $product->update(['status' => 'draft']);
    expect(fn () => $service->confirm($this->admin, $preview))->toThrow(ValidationException::class);
    expect(DB::table('catalog_actions')->count())->toBe(0);
    $id = $service->confirm($this->admin, $service->preview($this->admin, matrixParameters($product)));
    expect(fn () => $product->update(['status' => 'active']))->toThrow(ValidationException::class);
    $failed = false;
    DB::listen(function ($query) use (&$failed) {
        if (! $failed && str_starts_with(strtolower($query->sql), 'update "products"')) {
            $failed = true;
            throw new RuntimeException('injected batch failure');
        }
    });
    expect(fn () => $service->batch($id, 0))->toThrow(RuntimeException::class);
    expect(DB::table('catalog_actions')->find($id)->last_id)->toBe(0)->and($product->refresh()->status->value)->toBe('draft');
    $service->failure($id, 0, new RuntimeException('injected'));
    $service->resume($this->admin, $id);
    finishCatalogAction($id);
    $service->batch($id, 0);
    expect($product->refresh()->status->value)->toBe('archived')->and(DB::table('catalog_action_items')->whereNotNull('result')->count())->toBe(1);
});

test('livewire status workflow retains filters on pagination authorizes and uses explicit confirmation', function () {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $product = matrixProduct('usilitel');
    Product::factory()->forPartType($product->partType)->count(22)->create(['status' => 'active']);
    $page = Livewire::test(CatalogActionsPanel::class)->set('parameters', matrixParameters($product))
        ->call('calculate')->assertHasNoErrors()->assertSet('preview.counts.products', 23)->call('gotoPage', 2)
        ->set('search', $product->title)->assertSet('paginators.page', 1);
    expect(DB::table('catalog_actions')->count())->toBe(0);
    $page->call('confirm')->assertHasNoErrors();
    finishCatalogAction($page->get('operationId'));
    expect(Product::where('part_type_id', $product->part_type_id)->where('status', 'archived')->count())->toBe(23);
    $this->actingAs(User::factory()->create());
    Livewire::test(CatalogActionsPanel::class)->assertForbidden();
});

test('configuration prices and allowed choices reach product page cart and immutable checkout snapshot', function () {
    $product = matrixProduct('soedinitel');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $row = CatalogConfiguration::where('part_type_id', $product->part_type_id)->get()->first(fn ($r) => $r->options === ['position' => 'both', 'material' => 'galvanized', 'thickness' => '1_5mm']);
    expect($row->price)->toBe('4400.00');
    $variant = $product->variants()->where('catalog_configuration_id', $row->id)->firstOrFail();
    $this->get(route('products.show', $product->slug))->assertOk();
    $cart = Cart::factory()->create();
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->post(route('cart.items.store'), ['product_variant_id' => $variant->id, 'quantity' => 2])->assertSessionHasNoErrors();
    $delivery = DeliveryMethodSetting::factory()->create(['code' => 'courier', 'base_price' => 490, 'price_mode' => 'fixed', 'is_active' => true]);
    $payment = PaymentMethodSetting::factory()->create(['code' => 'card', 'is_active' => true]);
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->post(route('checkout.store'), [
        'customer_name' => 'Иван Петров', 'customer_phone' => '+79990000000', 'customer_email' => 'ivan@example.test', 'customer_city' => 'Москва',
        'delivery_method' => $delivery->code->value, 'payment_method' => $payment->code->value, 'agree_terms' => '1'])->assertSessionHasNoErrors();
    $order = Order::firstOrFail();
    expect($order->subtotal)->toBe('8800.00');
    expect($variant->title)->toBe('Левый + Правый / Оцинковка / 1,5 мм')
        ->and($variant->options)->not->toBeEmpty()
        ->and($order->items()->firstOrFail()->title_snapshot)->toContain($variant->title)
        ->and($order->items()->firstOrFail()->options_snapshot)->toBe($variant->publicOptionsSnapshot());
    $oldOrderItem = $order->items()->firstOrFail()->toArray();
    DB::table('product_variants')->where('id', $variant->id)->update(['title' => null, 'options' => null]);
    DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
    expect($variant->fresh()->title)->toBe('Левый + Правый / Оцинковка / 1,5 мм')
        ->and($order->items()->firstOrFail()->toArray())->toBe($oldOrderItem);
    DB::table('product_variants')->where('id', $variant->id)->update(['is_active' => false]);
    expect($order->refresh()->subtotal)->toBe('8800.00')->and($order->items()->firstOrFail()->product_variant_id)->toBe($variant->id);
});

test('historical JSON combination retains its variant ID individual price and order-compatible snapshot', function () {
    $product = matrixProduct('soedinitel');
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '777.00', 'price_mode' => 'individual',
        'options' => ['position' => ['group' => 'Положение', 'value' => 'Левый'], 'material' => ['group' => 'Материал', 'value' => 'ХКС'], 'thickness' => ['group' => 'Толщина', 'value' => '1 мм']]]);
    DB::transaction(fn () => $this->matrix->synchronize($product));
    expect($variant->refresh()->is_active)->toBeTrue()->and($variant->price)->toBe('777.00')->and($variant->optionValues()->count())->toBe(3);
    expect($product->variants()->count())->toBe(12);
    expect(app(StorefrontProductAvailability::class)->isPurchasable($variant->fresh()))->toBeTrue();
    DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
    expect($product->variants()->count())->toBe(12)->and($variant->fresh()->price)->toBe('777.00');
});

test('canceling a partially applied operation preserves committed batches and publishes only them', function () {
    $product = matrixProduct('usilitel');
    Product::factory()->forPartType($product->partType)->count(100)->create(['status' => 'active']);
    $service = app(CatalogActions::class);
    $id = $service->confirm($this->admin, $service->preview($this->admin, matrixParameters($product, extra: ['target_status' => 'draft'])));
    $service->batch($id, 0);
    $service->batch($id, 0);
    expect(Product::where('status', 'draft')->count())->toBe(100);
    $service->cancel($this->admin, $id);
    $service->batch($id, (int) DB::table('catalog_actions')->find($id)->last_id);
    expect(Product::where('status', 'active')->count())->toBe(1);
    $service->publish($id);
    $service->publish($id);
    expect(DB::table('catalog_actions')->find($id)->published_at)->not->toBeNull()
        ->and(DB::table('catalog_action_items')->whereNotNull('result')->count())->toBe(100);
});

test('a variant retired after entering a cart cannot be ordered', function () {
    $product = matrixProduct('soedinitel');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $variant = $product->variants()->where('is_active', true)->firstOrFail();
    $cart = Cart::factory()->create();
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->post(route('cart.items.store'), ['product_variant_id' => $variant->id, 'quantity' => 1])->assertSessionHasNoErrors();
    DB::table('product_variants')->where('id', $variant->id)->update(['is_active' => false]);
    $delivery = DeliveryMethodSetting::factory()->create(['code' => 'courier', 'base_price' => 490, 'price_mode' => 'fixed', 'is_active' => true]);
    $payment = PaymentMethodSetting::factory()->create(['code' => 'card', 'is_active' => true]);
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->post(route('checkout.store'), [
        'customer_name' => 'Иван Петров', 'customer_phone' => '+79990000000', 'customer_email' => 'ivan@example.test', 'customer_city' => 'Москва',
        'delivery_method' => $delivery->code->value, 'payment_method' => $payment->code->value, 'agree_terms' => '1'])->assertSessionHasErrors();
    expect(Order::count())->toBe(0);
});

test('search publication retries unacknowledged documents without losing status changes', function () {
    $product = matrixProduct('usilitel');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $service = app(CatalogActions::class);
    $id = $service->confirm($this->admin, $service->preview($this->admin, matrixParameters($product)));
    finishCatalogAction($id);
    config(['scout.driver' => 'meilisearch']);
    $documents = [];
    $index = Mockery::mock(Indexes::class);
    $index->shouldReceive('addDocuments')->twice()->andReturnUsing(function ($rows) use (&$documents) {
        $documents = $rows;

        return ['taskUid' => 42];
    });
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->twice()->andReturn($index);
    $attempt = 0;
    $client->shouldReceive('waitForTask')->twice()->andReturnUsing(function () use (&$attempt) {
        if (++$attempt === 1) {
            throw new RuntimeException('Meilisearch acknowledgement unavailable');
        }

        return ['status' => 'succeeded'];
    });
    app()->instance(Client::class, $client);
    expect(fn () => $service->publish($id))->toThrow(RuntimeException::class);
    expect(DB::table('catalog_actions')->find($id)->publication_cursor)->toBe(0)
        ->and(DB::table('catalog_actions')->find($id)->published_at)->toBeNull();
    $service->publish($id);
    $service->publish($id);
    expect(DB::table('catalog_actions')->find($id)->published_at)->not->toBeNull()
        ->and($product->fresh()->status->value)->toBe('archived')
        ->and($documents[0]['is_active'])->toBeFalse();
});

test('explicit matrix application aligns inherited prices and preserves unknown prices with matching IDs', function () {
    $product = matrixProduct('soedinitel');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $variants = $product->variants()->orderBy('id')->get();
    $inherited = $variants[0];
    $historical = $variants[1];
    DB::table('product_variants')->where('id', $inherited->id)->update(['price' => '1.00', 'price_mode' => 'inherited']);
    DB::table('product_variants')->where('id', $historical->id)->update(['price' => '777.00', 'price_mode' => 'unknown']);
    DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
    expect($inherited->fresh()->price)->toBe(CatalogConfiguration::findOrFail($inherited->catalog_configuration_id)->price)
        ->and($historical->fresh()->price)->toBe('777.00')
        ->and($product->variants()->orderBy('id')->pluck('id')->all())->toBe($variants->modelKeys());
});

test('configured offers leave the Yandex feed after bulk archive and publication invalidates its durable state', function () {
    $product = matrixProduct('soedinitel');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $directory = storage_path('framework/testing/config-feed-'.bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($directory);
    config(['yandex-feed.directory' => $directory, 'app.url' => 'https://shop.example.test']);
    try {
        $generator = app(YandexFeedGenerator::class);
        $generator->generate($directory.'/test.yml');
        $xml = simplexml_load_file($directory.'/test.yml');
        expect($xml->shop->offers->offer)->toHaveCount(12);
        foreach ($xml->shop->offers->offer as $offer) {
            $variant = ProductVariant::findOrFail((int) str_replace('variant-', '', (string) $offer['id']));
            expect((string) $offer->name)->toContain($variant->title);
        }
        $service = app(CatalogActions::class);
        $id = $service->confirm($this->admin, $service->preview($this->admin, matrixParameters($product)));
        finishCatalogAction($id);
        config(['yandex-feed.enabled' => true]);
        $state = app(YandexFeedState::class);
        $before = $state->read()['source_revision'];
        $service->publish($id);
        $service->publish($id);
        expect($state->read()['source_revision'])->toBeGreaterThan($before);
        $generator->generate($directory.'/test.yml');
        expect(simplexml_load_file($directory.'/test.yml')->shop->offers->offer)->toHaveCount(0);
    } finally {
        config(['yandex-feed.enabled' => false]);
        File::deleteDirectory($directory);
    }
});


test('configuration preview counts real differences and repeated application is a database no-op', function () {
    $product = matrixProduct('porog');
    $service = app(CatalogActions::class);
    $parameters = matrixParameters($product, 'configurations');
    expect($service->preview($this->admin,$parameters)['counts']['changed'])->toBe(1);
    $id=$service->confirm($this->admin,$service->preview($this->admin,$parameters)); finishCatalogAction($id);
    $before=$product->variants()->orderBy('id')->get()->toArray();
    $repeat=$service->preview($this->admin,$parameters);
    expect($repeat['counts'])->toMatchArray(['changed'=>0,'same'=>1,'variants_to_create'=>0,'variants_to_update'=>0,'variants_to_retire'=>0]);
    $id=$service->confirm($this->admin,$repeat);finishCatalogAction($id);
    expect(json_decode(DB::table('catalog_actions')->find($id)->report,true)['changed'])->toBe(0)
        ->and($product->variants()->orderBy('id')->get()->toArray())->toBe($before);
    DB::table('product_variants')->where('id',$before[0]['id'])->update(['title'=>null]);
    expect($service->preview($this->admin,$parameters)['counts']['changed'])->toBe(1);
});

test('a default variant after historical offers is not repeatedly marked as changed', function () {
    $product = matrixProduct('arka/zadniaia');
    $historical = ProductVariant::factory()->count(24)->forProduct($product)->create([
        'is_active' => true,
        'is_default' => false,
        'price_mode' => 'unknown',
    ]);
    $historicalIds = $historical->modelKeys();
    $parameters = matrixParameters($product, 'configurations');
    $actions = app(CatalogActions::class);

    expect($actions->preview($this->admin, $parameters)['counts']['changed'])->toBe(1);
    DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));

    $default = $product->variants()->where('is_default', true)->firstOrFail();
    expect($default->id)->toBeGreaterThan(max($historicalIds));
    expect($product->variants()->whereIn('id', $historicalIds)->where('is_active', true)->exists())->toBeFalse();

    // This reproduces the real 501k-variant catalog: the default is at offset 24.
    $loaded = $product->fresh()->load(['variants' => fn ($query) => $query->orderBy('id'), 'variants.optionValues.group']);
    $rawKeys = $loaded->variants->where('is_default', true)->modelKeys();
    expect(array_keys($rawKeys))->not->toBe([0]);
    expect($this->matrix->changes($loaded)['changed'])->toBeFalse();
    expect($actions->preview($this->admin, $parameters)['counts'])->toMatchArray([
        'changed' => 0,
        'same' => 1,
        'variants_to_create' => 0,
        'variants_to_update' => 0,
        'variants_to_retire' => 0,
    ]);

    $before = $product->variants()->orderBy('id')->get()->toArray();
    DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
    expect($product->variants()->orderBy('id')->get()->toArray())->toBe($before);
});

test('changed CSV requires a current explicit confirmation retires removed combinations and preserves administrative prices', function () {
    $source=base_path('tests/Fixtures/catalog-configurations.csv');
    $file=fopen($source,'rb');$lines=[];while(($line=fgetcsv($file,escape:''))!==false){$lines[]=$line;}fclose($file);
    $lines=array_values(array_filter($lines,fn($line)=>trim($line[0]??'')!=='Ремкомплект пола'));
    $lines[1][4]='0,8 мм';
    $lines[2][5]='9999';
    $path=tempnam(sys_get_temp_dir(),'matrix-revision-');$file=fopen($path,'wb');foreach($lines as $line){fputcsv($file,$line,escape:'');}fclose($file);
    try {
        $product=matrixProduct('remkomplekt-pola');
        DB::transaction(fn()=> $this->matrix->synchronize($product));
        $oldIds=$product->variants()->pluck('id')->all();
        $reviewer=app(\App\Services\Catalog\CatalogConfigurationInstallation::class);
        $rows=app(CatalogConfigurationSource::class)->read($path);
        $review=$reviewer->preview($rows);
        expect($review['requires_confirmation'])->toBeTrue()->and($review['retired'])->not->toBeEmpty()->and($review['added'])->not->toBeEmpty();
        $before=CatalogConfiguration::orderBy('id')->get()->toArray();
        expect(fn()=> $this->matrix->install($path))->toThrow(ValidationException::class);
        expect(CatalogConfiguration::orderBy('id')->get()->toArray())->toBe($before);
        $priceRow=CatalogConfiguration::findOrFail($review['price_conflicts'][0]['id']);$priceRow->update(['price'=>'888.00']);
        expect(fn()=> $this->matrix->install($path,$review['confirmation']))->toThrow(ValidationException::class);
        $review=$reviewer->preview($rows);$this->matrix->install($path,$review['confirmation']);
        expect($priceRow->fresh()->price)->toBe('888.00')->and($this->matrix->rows($product->part_type_id))->toBeEmpty()
            ->and($product->variants()->pluck('id')->all())->toBe($oldIds)
            ->and(app(StorefrontProductAvailability::class)->isPurchasable($product->variants()->firstOrFail()))->toBeFalse();
        $return=$reviewer->preview(app(CatalogConfigurationSource::class)->read($source));
        expect($return['reactivated'])->not->toBeEmpty();
        $this->matrix->install($source,$return['confirmation']);
        expect($product->variants()->pluck('id')->all())->toBe($oldIds)->and($this->matrix->rows($product->part_type_id))->toHaveCount(2)
            ->and($priceRow->fresh()->price)->toBe('888.00');
    } finally {unlink($path);}
});

/** Preserve quoted newlines in the original six-column configuration header. */
function catalogConfigurationCsvRows(string $path): array
{
    $handle = fopen($path, 'rb');
    $rows = [];
    try {
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
    } finally {
        fclose($handle);
    }

    return $rows;
}

/** Map the stable option code back to the exact CSV value, never the display title. */
function matrixCsvMaterial(ProductVariant $variant): string
{
    $code = $variant->optionValues()
        ->whereHas('group', fn ($query) => $query->where('code', 'material'))
        ->firstOrFail()->code;

    $label = array_search($code, CatalogConfigurationSource::VALUES['material'], true);
    if ($label === false) {
        throw new LogicException('Unknown material code in the matrix regression fixture: '.$code);
    }

    return $label;
}

test('CSV removal and return restores only matrix-retired variants with the same IDs', function () {
    $product = matrixProduct('remkomplekt-pola');
    DB::transaction(fn () => $this->matrix->synchronize($product));

    $originalIds = $product->variants()->orderBy('id')->pluck('id')->all();
    $manual = $product->variants()->where('is_default', false)->firstOrFail();
    $automatic = $product->variants()->where('is_default', true)->firstOrFail();
    $manual->update(['is_active' => false]);
    expect($manual->fresh()->configuration_auto_retired)->toBeFalse();

    $source = base_path('tests/Fixtures/catalog-configurations.csv');
    $lines = catalogConfigurationCsvRows($source);
    $retiredMaterial = matrixCsvMaterial($automatic);
    $lines = array_values(array_filter($lines, fn (array $row): bool =>
        $row[0] !== 'Ремкомплект пола' || $row[3] !== $retiredMaterial
    ));
    expect($lines)->toHaveCount(count(catalogConfigurationCsvRows($source)) - 1);
    $path = tempnam(sys_get_temp_dir(), 'matrix-return-');
    $handle = fopen($path, 'wb');
    foreach ($lines as $line) {
        fputcsv($handle, $line, escape: '');
    }
    fclose($handle);

    try {
        $reviewer = app(\App\Services\Catalog\CatalogConfigurationInstallation::class);
        $preview = $reviewer->preview(app(CatalogConfigurationSource::class)->read($path));
        $this->matrix->install($path, $preview['confirmation']);
        $before = $this->matrix->changes($product->fresh());
        expect($before['retired_variants'])->toBe(1);
        DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
        $automaticAfterRemoval = $automatic->fresh();
        $manualAfterRemoval = $manual->fresh();
        expect($automaticAfterRemoval->is_active)->toBeFalse()
            ->and($automaticAfterRemoval->configuration_auto_retired)->toBeTrue()
            ->and($manualAfterRemoval->is_active)->toBeFalse()
            ->and($manualAfterRemoval->configuration_auto_retired)->toBeFalse()
            ->and($this->matrix->changes($product->fresh())['changed'])->toBeFalse();

        $restore = $reviewer->preview(app(CatalogConfigurationSource::class)->read($source));
        $this->matrix->install($source, $restore['confirmation']);
        expect($this->matrix->changes($product->fresh())['changed'])->toBeTrue();
        DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
        $automaticAfterRestore = $automatic->fresh();
        $manualAfterRestore = $manual->fresh();
        expect($product->variants()->orderBy('id')->pluck('id')->all())->toBe($originalIds)
            ->and($automaticAfterRestore->is_active)->toBeTrue()
            ->and($automaticAfterRestore->configuration_auto_retired)->toBeFalse()
            ->and($manualAfterRestore->is_active)->toBeFalse()
            ->and($manualAfterRestore->configuration_auto_retired)->toBeFalse()
            ->and($this->matrix->changes($product->fresh())['changed'])->toBeFalse();
    } finally {
        unlink($path);
    }
});

test('removing a whole matrix group retires managed offers and prevents legacy import fallback', function () {
    $product = matrixProduct('remkomplekt-pola');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $originalIds = $product->variants()->orderBy('id')->pluck('id')->all();
    $source = base_path('tests/Fixtures/catalog-configurations.csv');
    $lines = catalogConfigurationCsvRows($source);
    $lines = array_values(array_filter($lines, fn (array $row): bool => $row[0] !== 'Ремкомплект пола'));
    $path = tempnam(sys_get_temp_dir(), 'matrix-empty-');
    $handle = fopen($path, 'wb');
    foreach ($lines as $line) {
        fputcsv($handle, $line, escape: '');
    }
    fclose($handle);

    try {
        $reviewer = app(\App\Services\Catalog\CatalogConfigurationInstallation::class);
        $review = $reviewer->preview(app(CatalogConfigurationSource::class)->read($path));
        $this->matrix->install($path, $review['confirmation']);
        expect(fn () => app(\App\Services\Import\CatalogImportProductDefaults::class)->templateFor($product->partType))
            ->toThrow(ValidationException::class);
        $parameters = matrixParameters($product, 'configurations');
        $action = app(CatalogActions::class);
        $preview = $action->preview($this->admin, $parameters);
        expect($preview['counts'])->toMatchArray([
            'changed' => 1,
            'protected' => 0,
            'variants_to_retire' => 2,
        ]);
        finishCatalogAction($action->confirm($this->admin, $preview));
        expect($product->variants()->where('is_active', true)->count())->toBe(0)
            ->and($product->variants()->where('configuration_auto_retired', true)->count())->toBe(2)
            ->and($action->preview($this->admin, $parameters)['counts']['changed'])->toBe(0);

        $restore = $reviewer->preview(app(CatalogConfigurationSource::class)->read($source));
        $this->matrix->install($source, $restore['confirmation']);
        finishCatalogAction($action->confirm($this->admin, $action->preview($this->admin, $parameters)));
        expect($product->variants()->orderBy('id')->pluck('id')->all())->toBe($originalIds)
            ->and($product->variants()->where('is_active', true)->count())->toBe(2)
            ->and($product->variants()->where('configuration_auto_retired', true)->count())->toBe(0)
            ->and($action->preview($this->admin, $parameters)['counts']['changed'])->toBe(0);
    } finally {
        unlink($path);
    }
});

test('administrator can explicitly keep a matrix-retired offer disabled without losing its identity', function () {
    $product = matrixProduct('remkomplekt-pola');
    DB::transaction(fn () => $this->matrix->synchronize($product));
    $variant = $product->variants()->firstOrFail();
    $material = matrixCsvMaterial($variant);
    $source = base_path('tests/Fixtures/catalog-configurations.csv');
    $lines = catalogConfigurationCsvRows($source);
    $lines = array_values(array_filter($lines, fn (array $row): bool => $row[0] !== 'Ремкомплект пола' || $row[3] !== $material));
    expect($lines)->toHaveCount(count(catalogConfigurationCsvRows($source)) - 1);
    $path = tempnam(sys_get_temp_dir(), 'matrix-manual-');
    $handle = fopen($path, 'wb');
    foreach ($lines as $line) {
        fputcsv($handle, $line, escape: '');
    }
    fclose($handle);

    try {
        $reviewer = app(\App\Services\Catalog\CatalogConfigurationInstallation::class);
        $review = $reviewer->preview(app(CatalogConfigurationSource::class)->read($path));
        $this->matrix->install($path, $review['confirmation']);
        DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
        $retiredVariant = $variant->fresh();
        expect($retiredVariant->is_active)->toBeFalse()
            ->and($retiredVariant->configuration_auto_retired)->toBeTrue();

        $id = $variant->id;
        app(\App\Services\Catalog\ProductVariantAdminService::class)->save($variant->fresh(), ['is_active' => false]);
        $explicitlyDisabled = $variant->fresh();
        expect($explicitlyDisabled->is_active)->toBeFalse()
            ->and($explicitlyDisabled->configuration_auto_retired)->toBeFalse();
        $restore = $reviewer->preview(app(CatalogConfigurationSource::class)->read($source));
        $this->matrix->install($source, $restore['confirmation']);
        DB::transaction(fn () => $this->matrix->synchronize($product->fresh()));
        $restoredVariant = $variant->fresh();
        expect($restoredVariant->id)->toBe($id)
            ->and($restoredVariant->is_active)->toBeFalse()
            ->and($restoredVariant->configuration_auto_retired)->toBeFalse();
    } finally {
        unlink($path);
    }
});
