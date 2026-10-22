<?php

use App\Filament\Pages\CatalogPricesPage;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Jobs\ApplyCatalogPrices;
use App\Models\ImportRun;
use App\Models\OrderItem;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CatalogBulkPrices;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\Catalog\CatalogPricingSchema;
use App\Services\Feeds\YandexFeedInvalidator;
use App\Services\Import\CatalogImportPriceResolver;
use App\Services\Search\CatalogSearchDocument;
use App\Services\StorefrontProductAvailability;
use App\ViewModels\ProductCardViewModel;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Storage::fake('public');
    $this->actingAs($this->admin = User::factory()->superAdmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->prices = app(CatalogBulkPrices::class);
    $this->type = PartType::factory()->create(['base_price' => '100.00']);
});

function pricedProduct(PartType $type, string $mode = 'inherited', string $price = '100.00', int $variants = 24): Product
{
    $product = Product::factory()->forPartType($type)->create(['price_mode' => $mode, 'price' => $price]);
    for ($i = 0; $i < $variants; $i++) {
        ProductVariant::factory()->create(['product_id' => $product->id, 'price_mode' => $mode, 'price' => $product->price, 'is_default' => $i === 0]);
    }

    return $product->refresh();
}

function runPriceChange($test, bool $children = false, bool $all = false, array $overrides = []): int
{
    $preview = $test->prices->preview($test->type->id, $children, '220.50', $all, $overrides);
    $id = $test->prices->confirm($test->admin, $preview, $all, $overrides);
    while (! $test->prices->batch($id)) {
    }

    return $id;
}

test('bulk prices update 24 inherited variants and archives without touching unrelated goods or identity', function () {
    $p = pricedProduct($this->type);
    $p->update(['status' => 'archived']);
    $before = $p->variants()->get(['id', 'sku', 'options', 'is_active'])->toArray();
    $other = pricedProduct(PartType::factory()->create(['base_price' => 50]), variants: 1);
    $generic = Product::factory()->generic()->create(['price' => 72]);
    $id = runPriceChange($this);
    expect($p->refresh()->price)->toBe('220.50')->and($p->status->value)->toBe('archived')
        ->and($p->variants()->where('price', '220.50')->count())->toBe(24)
        ->and($p->variants()->get(['id', 'sku', 'options', 'is_active'])->toArray())->toBe($before)
        ->and($other->refresh()->price)->toBe('50.00')->and($generic->refresh()->price)->toBe('72.00');
    $report = DB::table('catalog_price_operations')->find($id);
    expect(json_decode($report->report, true))->toMatchArray(['products' => 1, 'variants' => 24, 'archived' => 1]);
    $this->prices->batch($id);
    expect(DB::table('catalog_price_operations')->find($id)->report)->toBe($report->report);
});

test('parent scopes include descendants and types without products', function () {
    $child = PartType::factory()->create(['parent_id' => $this->type->id, 'base_price' => 70]);
    $grandchild = PartType::factory()->create(['parent_id' => $child->id]);
    $p = pricedProduct($child, variants: 1);
    runPriceChange($this, children: true);
    expect($p->refresh()->price)->toBe('220.50')->and($grandchild->refresh()->base_price)->toBe('220.50');
});

test('unknown historical and individual prices are protected including equal base prices', function () {
    $unknown = pricedProduct($this->type, 'unknown', '100.00', 1);
    $individual = pricedProduct($this->type, 'individual', '100.00', 1);
    $p = pricedProduct($this->type, variants: 2);
    $v = $p->variants()->first();
    $v->update(['price' => 333]);
    runPriceChange($this);
    expect($unknown->refresh()->price)->toBe('100.00')->and($individual->refresh()->price)->toBe('100.00')
        ->and($p->refresh()->price)->toBe('220.50')->and($v->refresh()->price)->toBe('333.00')
        ->and($p->variants()->where('price', '220.50')->count())->toBe(1);
});

test('per product decisions and replace all allow explicit alignment while preserving opt outs', function () {
    $a = pricedProduct($this->type, 'individual', '777.00', 2);
    $b = pricedProduct($this->type, 'individual', '888.00', 1);
    $a->variants()->first()->update(['price' => 999]);
    runPriceChange($this, all: true, overrides: [$b->id => false]);
    expect($a->refresh()->price_mode)->toBe('inherited')->and($a->variants()->where('price', '220.50')->count())->toBe(2)
        ->and($b->refresh()->price)->toBe('888.00');
});

test('preview writes nothing and rejects stale prices modes membership and decisions', function () {
    $p = pricedProduct($this->type, variants: 1);
    $preview = $this->prices->preview($this->type->id, false, '200');
    expect(DB::table('catalog_price_operations')->count())->toBe(0)->and($p->refresh()->price)->toBe('100.00');
    $p->variants()->first()->update(['price' => 111]);
    expect(fn () => $this->prices->confirm($this->admin, $preview))->toThrow(ValidationException::class);
    $fresh = $this->prices->preview($this->type->id, false, '200');
    expect(fn () => $this->prices->confirm($this->admin, $fresh, true))->toThrow(ValidationException::class);
});

test('pending operation blocks other confirmations edits and import final publication', function () {
    $p = pricedProduct($this->type, variants: 1);
    $preview = $this->prices->preview($this->type->id, false, '200');
    $id = $this->prices->confirm($this->admin, $preview);
    expect(fn () => $p->update(['price' => 5]))->toThrow(ValidationException::class)
        ->and(fn () => $this->prices->confirm($this->admin, $preview))->toThrow(ValidationException::class)
        ->and(fn () => DB::transaction(fn () => app(CatalogPriceGuard::class)->assertWritable()))->toThrow(ValidationException::class);
    $this->prices->batch($id);
    expect(app(CatalogPriceGuard::class)->busy())->toBeFalse();
});

test('active excel import prevents price confirmation', function () {
    ImportRun::factory()->create(['status' => 'running_rows']);
    $preview = $this->prices->preview($this->type->id, false, '200');
    expect(fn () => $this->prices->confirm($this->admin, $preview))->toThrow(ValidationException::class);
});

test('bounded batches commit progress and resume with no duplicate effects', function () {
    // Insert representative volume without model setup overhead, preserving production schema.
    $p = pricedProduct($this->type, variants: 1);
    $row = DB::table('products')->where('id', $p->id)->first();
    for ($i = 0; $i < 110; $i++) {
        $copy = (array) $row;
        unset($copy['id']);
        $copy['slug'] = 'bulk-'.$i;
        $copy['sku'] = null;
        DB::table('products')->insert($copy);
    }
    $preview = $this->prices->preview($this->type->id, false, '200');
    $id = $this->prices->confirm($this->admin, $preview);
    expect($this->prices->batch($id))->toBeFalse()->and(Product::where('price', 200)->count())->toBe(100);
    // A late duplicate and its failure callback cannot consume or fail the next batch.
    (new ApplyCatalogPrices($id, 0))->handle($this->prices);
    (new ApplyCatalogPrices($id, 0))->failed(new RuntimeException('late failure'));
    expect(Product::where('price', 200)->count())->toBe(100)
        ->and(json_decode(DB::table('catalog_price_operations')->find($id)->report, true)['errors'])->toBe(0);
    // Worker disappears here. Recovery dispatches from the persisted cursor.
    DB::table('catalog_price_operations')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);
    $this->prices->dispatchPending();
    expect($this->prices->batch($id))->toBeTrue()->and(Product::where('price', 200)->count())->toBe(111);
    expect(json_decode(DB::table('catalog_price_operations')->find($id)->report, true)['products'])->toBe(111);
});

test('failed transaction leaves cursor and prices untouched and retries successfully', function () {
    $p = pricedProduct($this->type, variants: 1);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    DB::statement("CREATE TRIGGER reject_price BEFORE UPDATE ON product_variants BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    expect(fn () => $this->prices->batch($id))->toThrow(QueryException::class);
    expect($p->refresh()->price)->toBe('100.00')->and(DB::table('catalog_price_operations')->find($id)->last_id)->toBe(0);
    DB::statement('DROP TRIGGER reject_price');
    $this->prices->batch($id);
    expect($p->refresh()->price)->toBe('200.00');
});

test('filament editing description preserves inheritance changing price marks individual and explicit return restores it', function () {
    $p = pricedProduct($this->type, variants: 1);
    Livewire::test(EditProduct::class, ['record' => $p->id])->fillForm(['description' => 'Новое описание'])->call('save')->assertHasNoFormErrors();
    expect($p->refresh()->price_mode)->toBe('inherited');
    Livewire::test(EditProduct::class, ['record' => $p->id])->fillForm(['price' => 123])->call('save')->assertHasNoFormErrors();
    expect($p->refresh()->price_mode)->toBe('individual')->and($p->price)->toBe('123.00');
    Livewire::test(EditProduct::class, ['record' => $p->id])->fillForm(['price_mode' => 'inherited'])->call('save')->assertHasNoFormErrors();
    expect($p->refresh()->price_mode)->toBe('inherited')->and($p->price)->toBe('100.00');
});

test('mass management page no longer exposes legacy historical-price overrides', function () {
    Livewire::test(CatalogPricesPage::class)->assertSee('Массовое управление товарами')
        ->assertSee('Изменить статус товаров')->assertSee('Изменить цены')
        ->assertDontSee('Принять исторические цены');
    $product = pricedProduct($this->type, 'unknown', '555.00', 1);
    $overrides = [$product->id => true];
    $preview = $this->prices->preview($this->type->id, false, '250', overrides: $overrides);
    $id = $this->prices->confirm($this->admin, $preview, overrides: $overrides);
    $this->prices->batch($id);
    expect($product->refresh()->price)->toBe('250.00');
});

test('unauthorized users cannot view or apply prices', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(CatalogPricesPage::class)->assertForbidden();
    expect(fn () => $this->prices->confirm($user, []))->toThrow(HttpException::class);
});

test('preview query count grows by batches and not by products or variants', function () {
    foreach (range(1, 8) as $_) {
        pricedProduct($this->type);
    }
    DB::enableQueryLog();
    $preview = $this->prices->preview($this->type->id, false, '200');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($preview['counts']['variants'])->toBe(192)->and(count($queries))->toBeLessThan(12);
});

test('orders and search documents remain unchanged while feed publication waits for price completion', function () {
    $p = pricedProduct($this->type, variants: 1);
    $item = OrderItem::factory()->create(['product_id' => $p->id, 'product_variant_id' => $p->variants()->value('id')]);
    $snapshot = $item->refresh()->toArray();
    $p->load(CatalogSearchDocument::relations($p));
    $document = $p->toSearchableArray();
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    expect(app(YandexFeedInvalidator::class)->importInProgress())->toBeTrue();
    $this->prices->batch($id);
    expect($item->refresh()->toArray())->toBe($snapshot)->and($p->refresh()->toSearchableArray())->toBe($document)
        ->and(app(YandexFeedInvalidator::class)->importInProgress())->toBeFalse();
});

test('explicit equal price mode and variant only edits are saved by filament without losing inheritance elsewhere', function () {
    $p = pricedProduct($this->type, variants: 2);
    Livewire::test(EditProduct::class, ['record' => $p->id])->fillForm(['price_mode' => 'individual'])->call('save')->assertHasNoFormErrors();
    expect($p->refresh()->price_mode)->toBe('individual')->and($p->price)->toBe('100.00');
    $v = $p->variants()->first();
    Livewire::test(EditProduct::class, ['record' => $p->id])->set('data.variants.record-'.$v->id.'.price', '333.33')->call('save')->assertHasNoFormErrors();
    expect($v->refresh()->price_mode)->toBe('individual')->and($v->price)->toBe('333.33')
        ->and($p->variants()->where('price_mode', 'inherited')->count())->toBe(1);
});

test('migration seeds bases without classifying or rewriting legacy prices', function () {
    $p = pricedProduct($this->type, 'unknown', '876.54', 1);
    // Roll back the dependent guard migration first, as Laravel rollback does.
    // Its triggers intentionally require catalog_price_locks to remain present.
    $protections = require database_path('migrations/2026_10_20_000100_add_safe_partial_catalog_protections.php');
    $protections->down();
    $migration = require database_path('migrations/2026_10_13_000100_add_catalog_pricing.php');
    $migration->down();
    $migration->up();
    $protections->up();
    expect($p->refresh()->price)->toBe('876.54')->and($p->price_mode)->toBe('unknown')
        ->and($p->variants()->first()->price)->toBe('876.54')->and($p->variants()->first()->price_mode)->toBe('unknown')
        ->and(\Illuminate\Support\Facades\Schema::hasTable('catalog_import_protections'))->toBeTrue();
});

test('job failure is recorded and retry completes once with exception audit intact', function () {
    $p = pricedProduct($this->type, 'individual', '333.00', 2);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200', true), true);
    $job = new ApplyCatalogPrices($id);
    DB::statement("CREATE TRIGGER reject_job BEFORE UPDATE ON products BEGIN SELECT RAISE(ABORT, 'job failure'); END");
    expect(fn () => $job->handle($this->prices))->toThrow(QueryException::class);
    expect(DB::table('catalog_price_overrides')->count())->toBe(0)
        ->and(json_decode(DB::table('catalog_price_operations')->find($id)->report, true)['errors'])->toBe(1);
    DB::statement('DROP TRIGGER reject_job');
    $job->handle($this->prices);
    $job->handle($this->prices);
    expect($p->refresh()->price)->toBe('200.00')->and(DB::table('catalog_price_overrides')->count())->toBe(1)
        ->and(DB::table('catalog_price_overrides')->first()->product_id)->toBe($p->id)
        ->and(json_decode(DB::table('catalog_price_overrides')->first()->previous_prices, true)['price'])->toBe('333.00');
});

test('lost queue delivery remains recoverable from the committed operation', function () {
    $p = pricedProduct($this->type, variants: 1);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    DB::table('catalog_price_operations')->where('id', $id)->update(['next_attempt_at' => now()->subSecond()]);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    $this->prices->dispatchPending();
    expect(DB::table('catalog_price_operations')->find($id)->status)->toBe('pending')->and($p->refresh()->price)->toBe('100.00');
    (new ApplyCatalogPrices($id))->handle($this->prices);
    expect($p->refresh()->price)->toBe('200.00');
});

test('preview rejects newly scoped goods and changed base prices', function () {
    $preview = $this->prices->preview($this->type->id, false, '200');
    pricedProduct($this->type, variants: 1);
    expect(fn () => $this->prices->confirm($this->admin, $preview))->toThrow(ValidationException::class);
    $current = $this->prices->preview($this->type->id, false, '250');
    $this->type->update(['base_price' => 150]);
    expect(fn () => $this->prices->confirm($this->admin, $current))->toThrow(ValidationException::class);
    expect(DB::table('catalog_price_operations')->count())->toBe(0);
});

test('returning a product to inheritance preserves an individual technical variant', function () {
    $p = pricedProduct($this->type, 'individual', '333.00', 1);
    $v = $p->variants()->first();
    $v->update(['options' => ProductVariant::technicalOptions()]);
    Livewire::test(EditProduct::class, ['record' => $p->id])->fillForm(['price_mode' => 'inherited'])->call('save')->assertHasNoFormErrors();
    expect($p->refresh()->price)->toBe('100.00')->and($v->refresh()->price)->toBe('333.00')->and($v->price_mode)->toBe('individual');
});

test('finishing price publication can be recovered after a notification outage', function () {
    pricedProduct($this->type, variants: 1);
    $id = runPriceChange($this);
    expect(DB::table('catalog_price_operations')->find($id)->notified_at)->toBeNull();
    config(['yandex-feed.enabled' => true]);
    $this->mock(YandexFeedInvalidator::class, function ($mock) {
        $mock->shouldReceive('queryExecuted')->zeroOrMoreTimes();
        $mock->shouldReceive('invalidate')->once()->andThrow(new RuntimeException('feed cache unavailable'));
    });
    $this->prices->publishNotifications();
    expect(DB::table('catalog_price_operations')->find($id)->notified_at)->toBeNull();
    config(['yandex-feed.enabled' => false]);
    $this->prices->publishNotifications();
    expect(DB::table('catalog_price_operations')->find($id)->notified_at)->not->toBeNull();
});

test('base price resolver reads current database values for arbitrary future types without code changes', function () {
    $resolver = app(CatalogImportPriceResolver::class);
    $cached = $this->type;
    expect($resolver->resolve($cached)['price'])->toBe('100.00');
    runPriceChange($this);
    expect($resolver->resolve($cached)['price'])->toBe('220.50');
    $new = PartType::factory()->create();
    expect($resolver->resolve($new))->toBeNull();
    $new->update(['base_price' => '123456.78']);
    expect($resolver->resolve($new)['price'])->toBe('123456.78');
});

test('an editor opened before bulk pricing cannot overwrite the committed prices', function () {
    $p = pricedProduct($this->type, variants: 1);
    $page = Livewire::test(EditProduct::class, ['record' => $p->id]);
    runPriceChange($this);
    $page->fillForm(['description' => 'Старая открытая форма'])->call('save')->assertHasFormErrors(['price']);
    expect($p->refresh()->price)->toBe('220.50')->and($p->price_mode)->toBe('inherited');
});

test('numeric money normalization is exact and invalid prices do not create operations', function () {
    expect($this->prices->price('0000000123.4'))->toBe('123.40')->and($this->prices->price('9999999999.99'))->toBe('9999999999.99');
    foreach (['1.001', '-1', '1e4', 'NaN', '10000000000', ''] as $price) {
        expect(fn () => $this->prices->preview($this->type->id, false, $price))->toThrow(ValidationException::class);
    }
    expect(DB::table('catalog_price_operations')->count())->toBe(0);
});

test('description only edits preserve unknown historical ownership even when the default price differs', function () {
    $p = pricedProduct($this->type, 'unknown', '100.00', 1);
    $p->variants()->first()->update(['price' => 321]);
    Livewire::test(EditProduct::class, ['record' => $p->id])->fillForm(['description' => 'Описание'])->call('save')->assertHasNoFormErrors();
    expect($p->refresh()->price_mode)->toBe('unknown')->and($p->variants()->first()->price_mode)->toBe('individual');
});

test('stock and image updates remain independent while a price operation is pending', function () {
    $p = pricedProduct($this->type, variants: 1);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    $variant = $p->variants()->first();
    $variant->update(['stock_quantity' => 7]);
    $p->update(['description' => 'Описание не меняет цену']);
    $this->prices->batch($id);
    expect($variant->refresh()->stock_quantity)->toBe(7)->and($variant->price)->toBe('200.00');
});

test('new mass page contains no obsolete historical-exceptions table', function () {
    Livewire::test(CatalogPricesPage::class)
        ->assertSee('Изменить цены')->assertSee('Изменить статус товаров')
        ->assertDontSee('Принять исторические цены');
    $products = collect(range(1, 22))->map(fn () => pricedProduct($this->type, 'unknown', '101.00', 1));
    $preview = $this->prices->preview($this->type->id, false, '250');
    expect($preview['counts']['products'])->toBe(22)
        ->and(DB::table('catalog_price_operations')->count())->toBe(0)
        ->and($products->first()->refresh()->price)->toBe('101.00');
});

test('repeated saving the same livewire editor cannot revert propagated prices or price modes', function () {
    $p = pricedProduct($this->type);
    Livewire::test(EditProduct::class, ['record' => $p->id])->fillForm(['price' => 333])
        ->call('save')->assertHasNoFormErrors()->assertSet('data.price_mode', 'individual')
        ->call('save')->assertHasNoFormErrors();
    expect($p->refresh()->price)->toBe('333.00')->and($p->price_mode)->toBe('individual')
        ->and($p->variants()->where('price', 333)->where('price_mode', 'inherited')->count())->toBe(24);
});

test('historical adoption changes ownership across the whole selection without changing money or explicit exceptions', function () {
    $matching = collect(range(1, 23))->map(fn () => pricedProduct($this->type, 'unknown', '100.00', 2));
    $deviation = pricedProduct($this->type, 'unknown', '120.00', 1);
    $individual = pricedProduct($this->type, 'individual', '100.00', 1);
    $mixed = pricedProduct($this->type, 'unknown', '100.00', 2);
    DB::table('product_variants')->where('id', $mixed->variants()->first()->id)->update(['price' => 130]);
    $first = $matching->first();
    DB::table('products')->where('id', $first->id)->update(['old_price' => 150, 'last_import_price' => 80]);
    $before = DB::table('product_variants')->orderBy('id')->get(['id', 'price', 'old_price'])->toArray();
    $preview = $this->prices->preview($this->type->id, false, '100', action: 'adopt');
    expect($preview['counts'])->toMatchArray(['matching' => 23, 'deviation' => 1, 'individual' => 1, 'ambiguous' => 1, 'classified_products' => 23, 'classified_variants' => 46]);
    expect($first->refresh()->price_mode)->toBe('unknown');
    $id = $this->prices->confirm($this->admin, $preview);
    while (! $this->prices->batch($id)) {
    }
    expect($matching->last()->refresh()->price_mode)->toBe('inherited')
        ->and($first->refresh()->old_price)->toBe('150.00')->and($first->last_import_price)->toBe('80.00')
        ->and($deviation->refresh()->price_mode)->toBe('unknown')->and($mixed->refresh()->price_mode)->toBe('unknown')
        ->and($individual->refresh()->price_mode)->toBe('individual')
        ->and(DB::table('product_variants')->orderBy('id')->get(['id', 'price', 'old_price'])->toArray())->toEqual($before);
    $again = $this->prices->preview($this->type->id, false, '100', action: 'adopt');
    expect($again['counts']['classified_products'])->toBe(0)->and($again['counts']['classified_variants'])->toBe(0);
    $againId = $this->prices->confirm($this->admin, $again);
    $this->prices->batch($againId);
    expect(DB::table('catalog_price_overrides')->where('operation_id', $againId)->count())->toBe(0);
    runPriceChange($this);
    expect($first->refresh()->price)->toBe('220.50')->and($individual->refresh()->price)->toBe('100.00');
});

test('historical classification remains a preview-only backend operation and is absent from the customer interface', function () {
    $unchanged = pricedProduct($this->type, 'unknown', '100.00', 2);
    $deviation = pricedProduct($this->type, 'unknown', '130.00', 2);
    Livewire::test(CatalogPricesPage::class)->assertDontSee('Принять исторические цены');
    $preview = $this->prices->preview($this->type->id, false, '100', action: 'adopt', classifyDeviations: true);
    expect(DB::table('catalog_price_operations')->count())->toBe(0)
        ->and($unchanged->refresh()->price_mode)->toBe('unknown')
        ->and($deviation->refresh()->price)->toBe('130.00')
        ->and($preview)->toBeArray();
});

test('discount rendering hides invalid strike prices without destroying historical values', function () {
    $p = pricedProduct($this->type, variants: 1);
    $v = $p->variants()->first();
    $p->update(['old_price' => 250]);
    $v->update(['old_price' => 150]);
    $availability = app(StorefrontProductAvailability::class);
    expect($availability->effectiveOldPrice($v))->toBe('150.00');
    runPriceChange($this);
    expect($availability->effectiveOldPrice($v->refresh()))->toBeNull()
        ->and(ProductCardViewModel::fromProduct($p->fresh())->oldPrice)->toBeNull()
        ->and($v->old_price)->toBe('150.00')->and($p->refresh()->old_price)->toBe('250.00');
    $v->update(['old_price' => null]);
    expect($availability->effectiveOldPrice($v->refresh()))->toBe('250.00');
    $v->update(['old_price' => '220.50']);
    expect($availability->effectiveOldPrice($v))->toBeNull();
});

test('permanent failure releases reservation retains completed progress and can be explicitly resumed', function () {
    $p = pricedProduct($this->type, variants: 1);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    DB::statement("CREATE TRIGGER poison_price BEFORE UPDATE ON product_variants BEGIN SELECT RAISE(ABORT, 'poison product'); END");
    for ($i = 0; $i < 3; $i++) {
        expect(fn () => (new ApplyCatalogPrices($id))->handle($this->prices))->toThrow(QueryException::class);
    }
    expect(DB::table('catalog_price_operations')->find($id)->status)->toBe('failed')
        ->and(app(CatalogPriceGuard::class)->busy())->toBeFalse()->and($p->refresh()->price)->toBe('100.00');
    DB::statement('DROP TRIGGER poison_price');
    $this->prices->resume($this->admin, $id);
    $this->prices->batch($id);
    $this->prices->batch($id);
    expect($p->refresh()->price)->toBe('200.00')->and(DB::table('catalog_price_overrides')->count())->toBe(1);
});

test('application checks detect newer price variant base and membership mutations and cancellation frees catalog', function (string $target) {
    $p = pricedProduct($this->type, variants: 1);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    // Simulate an external writer bypassing Eloquent's reservation guard.
    match ($target) {
        'product' => DB::table('products')->where('id', $p->id)->update(['price' => 777, 'price_mode' => 'individual']),
        'variant' => DB::table('product_variants')->where('product_id', $p->id)->update(['old_price' => 777]),
        'base' => DB::table('part_types')->where('id', $this->type->id)->update(['base_price' => 777]),
        'membership' => DB::table('products')->where('id', $p->id)->update(['part_type_id' => null]),
    };
    $this->prices->batch($id);
    expect(DB::table('catalog_price_operations')->find($id)->status)->toBe('paused')
        ->and(DB::table('catalog_price_operations')->find($id)->last_id)->toBe(0);
    $this->prices->cancel($this->admin, $id);
    expect(app(CatalogPriceGuard::class)->busy())->toBeFalse();
    $this->prices->batch($id);
    expect(DB::table('catalog_price_operations')->find($id)->status)->toBe('canceled');
})->with(['product', 'variant', 'base', 'membership']);

test('repeated hard worker loss reaches a terminal state instead of locking the catalog forever', function () {
    pricedProduct($this->type, variants: 1);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    for ($i = 0; $i < 4; $i++) {
        DB::table('catalog_price_operations')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);
        $this->prices->dispatchPending();
    }
    expect(DB::table('catalog_price_operations')->find($id)->status)->toBe('failed')->and(app(CatalogPriceGuard::class)->busy())->toBeFalse();
    $new = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '300'));
    expect(fn () => $this->prices->resume($this->admin, $id))->toThrow(ValidationException::class);
    $this->prices->batch($new);
});

test('schema readiness is scoped and invalidated after actual schema changes without per save inspection', function () {
    $schema = app(CatalogPricingSchema::class);
    expect($schema->ready())->toBeTrue();
    DB::enableQueryLog();
    for ($i = 0; $i < 20; $i++) {
        $schema->ready();
    }
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    Schema::rename('catalog_price_locks', 'temporarily_hidden_price_locks');
    expect($schema->ready())->toBeFalse();
    Schema::rename('temporarily_hidden_price_locks', 'catalog_price_locks');
    expect($schema->ready())->toBeTrue();
});

test('the simplified page never proposes or changes a historic base price', function () {
    $this->type->update(['base_price' => null]);
    $product = pricedProduct($this->type, 'unknown', '125.00', 1);
    Livewire::test(CatalogPricesPage::class)->assertSee('Изменить цены')
        ->assertDontSee('Анализ исторических цен')->assertDontSee('Принять исторические цены');
    expect($this->type->refresh()->base_price)->toBeNull()
        ->and($product->refresh()->price_mode)->toBe('unknown')
        ->and(DB::table('catalog_price_operations')->count())->toBe(0);
});

test('partial failure keeps applied batch audit and cancellation allows a fresh operation', function () {
    $p = pricedProduct($this->type, variants: 1);
    $row = (array) DB::table('products')->find($p->id);
    unset($row['id']);
    for ($i = 1; $i <= 100; $i++) {
        DB::table('products')->insert(array_replace($row, ['slug' => 'partial-'.$i, 'sku' => null]));
    }
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    $this->prices->batch($id);
    $cursor = DB::table('catalog_price_operations')->find($id)->last_id;
    DB::statement("CREATE TRIGGER poison_remaining BEFORE UPDATE ON products BEGIN SELECT RAISE(ABORT, 'remaining failure'); END");
    for ($i = 0; $i < 3; $i++) {
        expect(fn () => (new ApplyCatalogPrices($id))->handle($this->prices))->toThrow(QueryException::class);
    }
    expect(DB::table('catalog_price_operations')->find($id)->last_id)->toBe($cursor)
        ->and(DB::table('products')->where('price', 200)->count())->toBe(100)
        ->and(DB::table('catalog_price_overrides')->where('operation_id', $id)->count())->toBe(100)
        ->and(app(CatalogPriceGuard::class)->busy())->toBeFalse();
    DB::statement('DROP TRIGGER poison_remaining');
    $this->prices->cancel($this->admin, $id);
    runPriceChange($this);
    expect(DB::table('products')->where('price', '220.50')->count())->toBe(101);
});

test('adoption protects incomplete data and does not silently change existing inherited bases', function () {
    $unknown = pricedProduct($this->type, 'unknown', '100.00', 0);
    $preview = $this->prices->preview($this->type->id, false, '100', action: 'adopt');
    expect($preview['counts']['insufficient'])->toBe(1)->and($preview['counts']['classified_products'])->toBe(0);
    pricedProduct($this->type, variants: 1);
    expect(fn () => $this->prices->preview($this->type->id, false, '200', action: 'adopt'))->toThrow(ValidationException::class);
});

test('reserved operation rejects actual variant base and import startup writes', function () {
    $p = pricedProduct($this->type, variants: 1);
    $id = $this->prices->confirm($this->admin, $this->prices->preview($this->type->id, false, '200'));
    expect(fn () => $p->variants()->first()->update(['price' => 777]))->toThrow(ValidationException::class)
        ->and(fn () => $this->type->update(['base_price' => 777]))->toThrow(ValidationException::class)
        ->and(fn () => ImportRun::factory()->create(['status' => 'running_rows']))->toThrow(ValidationException::class);
    expect(DB::table('import_runs')->count())->toBe(0);
    $this->prices->batch($id);
});
