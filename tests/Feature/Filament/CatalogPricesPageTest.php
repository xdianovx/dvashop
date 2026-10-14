<?php

use App\Filament\Pages\CatalogPricesPage;
use App\Models\PartType;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogBulkPrices;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CatalogPricesFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->actingAs($this->admin = User::factory()->superAdmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->prices = app(CatalogBulkPrices::class);
});

function priceUi($test, int $count = 25)
{
    [$test->type, $test->sample] = CatalogPricesFixture::create($count);

    return Livewire::test(CatalogPricesPage::class)->fillForm(['typeId' => $test->type->id, 'price' => '250.00']);
}

function finishUiPrices($test, int $id): void
{
    while (! $test->prices->batch($id)) {
    }
}

test('actual form fields calculate confirm and refresh without missing field errors', function () {
    $page = priceUi($this)->call('previewPrices')->assertHasNoErrors()->assertSet('calculationState', 'ready');
    $page->call('replaceAllExceptions')->assertHasNoErrors()->call('confirmPrices')->assertHasNoErrors()->assertSet('calculationState', 'submitted');
    finishUiPrices($this, $page->get('operationId'));
    $page->call('refreshOperation')->assertSet('calculationState', 'idle')->assertSet('preview', null)->assertSee('Завершена');
    expect($this->sample->refresh()->price)->toBe('250.00')->and($this->sample->variants()->where('price', 250)->count())->toBe(24);
});

test('1949 exceptions and 46776 variants keep global and row decisions across real pagination search and confirmation', function () {
    $page = priceUi($this, 1949)->call('previewPrices')->assertSet('preview.counts.products', 1949)->assertSet('preview.counts.variants', 46776);
    $version = $page->get('preview.source_hash');
    $page->call('replaceAllExceptions')->assertSet('preview.counts.forced', 1949)->assertSet('preview.counts.variants_to_update', 46776)
        ->assertSet('overrides', [])->call('setDecision', $this->sample->id, '0')->assertSet('preview.counts.forced', 1948)
        ->call('gotoPage', 2)->assertSet('paginators.page', 2)
        ->set('search', $this->sample->title)->assertSet('paginators.page', 1)->assertSee($this->sample->title)
        ->assertSet('overrides.'.$this->sample->id, false)->assertSet('preview.source_hash', $version)
        ->set('search', '')->call('gotoPage', 98)->assertSet('calculationState', 'ready');
    expect(count($page->get('overrides')))->toBe(1);
    $page->call('preserveAll')->assertSet('overrides', [])->assertSet('preview.counts.products_to_update', 0)->assertSet('preview.counts.preserved', 1949);
    $page->call('replaceAllExceptions')->call('setDecision', $this->sample->id, '0')->call('confirmPrices')->assertHasNoErrors();
    finishUiPrices($this, $page->get('operationId'));
    expect($this->sample->refresh()->price)->toBe('100.00')
        ->and(Product::query()->where('part_type_id', $this->type->id)->where('price', 250)->count())->toBe(1948)
        ->and(DB::table('product_variants')->where('price', 250)->count())->toBe(46752)
        ->and(DB::table('catalog_price_overrides')->count())->toBe(1948);
});

test('default choice removes sparse override and follows current global decision', function () {
    $page = priceUi($this)->call('previewPrices')->call('replaceAllExceptions')->call('setDecision', $this->sample->id, '0');
    $page->assertSet('preview.counts.forced', 24)->call('setDecision', $this->sample->id, '')->assertSet('overrides', [])->assertSet('preview.counts.forced', 25);
    $page->call('preserveAll')->call('setDecision', $this->sample->id, '1')->assertSet('preview.counts.forced', 1)->call('confirmPrices')->assertHasNoErrors();
    finishUiPrices($this, $page->get('operationId'));
    expect(Product::query()->where('price', 250)->count())->toBe(1);
});

test('new price or scope marks preview dirty and hides confirmation until recalculation', function () {
    $page = priceUi($this)->call('previewPrices')->set('price', '300')->assertSet('calculationState', 'dirty')
        ->assertDontSeeHtml('wire:click="confirmPrices"')->call('confirmPrices')->assertHasErrors(['price']);
    expect(DB::table('catalog_price_operations')->count())->toBe(0);
    $page->call('previewPrices')->assertHasNoErrors()->assertSet('calculationState', 'ready')->call('confirmPrices')->assertHasNoErrors();
});

test('real concurrent catalog edits are rejected while changing decisions cannot silently accept them', function () {
    $page = priceUi($this)->call('previewPrices');
    $original = $page->get('preview.source_hash');
    $this->sample->update(['price' => 777]);
    $page->call('replaceAllExceptions')->assertSet('calculationState', 'dirty')->assertSet('preview.source_hash', $original)
        ->call('confirmPrices')->assertHasErrors(['price']);
    expect(DB::table('catalog_price_operations')->count())->toBe(0)->and($this->sample->refresh()->price)->toBe('777.00');
});

test('search pagination and rendering preserve source version and valid confirmation', function () {
    $page = priceUi($this)->call('previewPrices');
    $hash = $page->get('preview.hash');
    $page->call('gotoPage', 2)->set('search', (string) $this->sample->id)->assertSet('paginators.page', 1)->set('search', '')
        ->call('$refresh')->assertSet('preview.hash', $hash)->assertSee('Показано с')->assertDontSee('pagination.previous')->assertDontSee('Showing')
        ->call('confirmPrices')->assertHasNoErrors();
});

test('switching modes analyzes history without asking for a new price and preserves amounts', function () {
    $page = priceUi($this)->set('operationMode', 'adopt')->set('price', '')->call('analyzeHistory')->assertHasNoErrors()
        ->assertSet('preview.counts.classified_products', 25)->assertSet('preview.counts.products_to_update', 0)
        ->call('preserveAll')->assertSet('preview.counts.classified_products', 0)
        ->call('adoptAllMatching')->assertSet('preview.counts.classified_products', 25)
        ->call('setDecision', $this->sample->id, '0')->assertSet('preview.counts.classified_products', 24)
        ->call('confirmPrices')->assertHasNoErrors();
    finishUiPrices($this, $page->get('operationId'));
    expect(Product::query()->where('price_mode', 'inherited')->count())->toBe(24)
        ->and(Product::query()->where('price', '!=', 100)->count())->toBe(0)
        ->and(DB::table('product_variants')->where('price', '!=', 100)->count())->toBe(0);
    $page->call('refreshOperation')->set('operationMode', 'change')->fillForm(['price' => '350'])->call('previewPrices')->call('confirmPrices')->assertHasNoErrors();
});

test('replaying confirmation request cannot create a second operation even after completion', function () {
    $page = priceUi($this)->call('previewPrices')->call('replaceAllExceptions');
    $preview = $page->get('preview');
    $page->call('confirmPrices')->assertHasNoErrors();
    $id = $page->get('operationId');
    expect($this->prices->confirm($this->admin, $preview, true))->toBe($id);
    finishUiPrices($this, $id);
    expect($this->prices->confirm($this->admin, $preview, true))->toBe($id)->and(DB::table('catalog_price_operations')->count())->toBe(1);
    $page->call('confirmPrices')->assertHasNoErrors();
});

test('decisions normalize strings booleans and key order into the same hash', function () {
    [$type, $sample] = CatalogPricesFixture::create(3);
    $a = $this->prices->preview($type->id, false, '250', false, [$sample->id + 1 => '1', $sample->id => '0']);
    $b = $this->prices->preview($type->id, false, '0250.00', false, [$sample->id => false, $sample->id + 1 => true]);
    expect($a['hash'])->toBe($b['hash']);
    $c = $this->prices->preview($type->id, false, '300', true);
    expect($a['source_hash'])->toBe($c['source_hash'])->and($a['parameters_hash'])->not->toBe($c['parameters_hash'])
        ->and($a['decisions_hash'])->not->toBe($c['decisions_hash']);
});

test('missing fields report errors at form state paths without creating an operation', function () {
    $page = Livewire::test(CatalogPricesPage::class)->call('previewPrices')->assertHasErrors(['typeId' => 'required']);
    [$type] = CatalogPricesFixture::create(1);
    $page->fillForm(['typeId' => $type->id])->call('previewPrices')->assertHasErrors(['price' => 'required']);
    expect(DB::table('catalog_price_operations')->count())->toBe(0);
});

test('confirmation rejects a concurrent price edit even without another UI action', function () {
    $page = priceUi($this)->call('previewPrices');
    $this->sample->update(['price' => 777]);
    $page->call('confirmPrices')->assertHasErrors(['price']);
    expect(DB::table('catalog_price_operations')->count())->toBe(0)
        ->and(DB::table('catalog_price_items')->count())->toBe(0);
});

test('changing scope requires recalculation and another historical type gets its own base', function () {
    $page = priceUi($this)->call('previewPrices')->set('descendants', false)->assertSet('calculationState', 'dirty');
    $other = PartType::factory()->create(['base_price' => '600.00']);
    $page->set('operationMode', 'adopt')->call('analyzeHistory')->assertSet('price', '100.00')
        ->set('typeId', $other->id)->assertSet('preview', null)->call('analyzeHistory')
        ->assertHasNoErrors()->assertSet('price', '600.00')->assertSet('preview.type_id', $other->id);
});

test('history without any known price reports an actionable type error instead of hidden price validation', function () {
    $type = PartType::factory()->create(['base_price' => null]);
    Livewire::test(CatalogPricesPage::class)->fillForm(['operationMode' => 'adopt', 'typeId' => $type->id])
        ->call('analyzeHistory')->assertHasErrors(['typeId'])->assertHasNoErrors(['price'])->assertSet('preview', null);
    expect(DB::table('catalog_price_operations')->count())->toBe(0);
});
