<?php

declare(strict_types=1);

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Http\Controllers\YandexFeedController;
use App\Jobs\RebuildYandexFeedJob;
use App\Models\Cart;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Models\PromoCode;
use App\Models\VehicleGeneration;
use App\Services\CartManager;
use App\Services\Feeds\YandexFeedCompressor;
use App\Services\Feeds\YandexFeedGenerator;
use App\Services\Feeds\YandexFeedInvalidator;
use App\Services\Feeds\YandexFeedRebuilder;
use App\Services\Feeds\YandexFeedState;
use App\Services\Feeds\YandexFeedValidator;
use App\Services\ImportStatusService;
use App\Services\StorefrontProductAvailability;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

final class CountingYandexFeedStreamWrapper
{
    public mixed $context;

    public static string $content = '';

    public static int $bytesRead = 0;

    private int $offset = 0;

    public static function reset(string $content): void
    {
        self::$content = $content;
        self::$bytesRead = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->offset = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$content, $this->offset, $count);
        $this->offset += strlen($chunk);
        self::$bytesRead += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen(self::$content);
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return self::stat();
    }

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return self::stat();
    }

    /** @return array<int|string, int> */
    private static function stat(): array
    {
        $values = [7, 424242, 0100644, 1, 1000, 1000, 0, strlen(self::$content), 1_700_000_000, 1_700_000_000, 1_700_000_000, -1, -1];

        return $values + [
            'dev' => $values[0], 'ino' => $values[1], 'mode' => $values[2], 'nlink' => $values[3],
            'uid' => $values[4], 'gid' => $values[5], 'rdev' => $values[6], 'size' => $values[7],
            'atime' => $values[8], 'mtime' => $values[9], 'ctime' => $values[10],
            'blksize' => $values[11], 'blocks' => $values[12],
        ];
    }
}

beforeEach(function (): void {
    config()->set([
        'yandex-feed.enabled' => true,
        'yandex-feed.directory' => storage_path('framework/testing/yandex-'.bin2hex(random_bytes(8))),
        'yandex-feed.connection' => 'yandex-feed',
        'yandex-feed.queue' => 'yandex-feed',
        'queue.connections.database.retry_after' => 660,
        'queue.connections.yandex-feed.retry_after' => 1500,
        'app.url' => 'https://shop.example.test',
        'filesystems.disks.public.url' => 'https://shop.example.test/storage',
    ]);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('public');
});

afterEach(function (): void {
    Http::assertNothingSent();
    config()->set('yandex-feed.enabled', false);
    File::deleteDirectory(config('yandex-feed.directory'));
});

function yandexVariant(array $attributes = []): ProductVariant
{
    return ProductVariant::factory()->create(array_replace([
        'price' => '1200.00', 'old_price' => '1500.00', 'stock_quantity' => 5,
        'stock_status' => StockStatus::InStock, 'title' => 'Материал: Оцинковка', 'sku' => 'YML-'.bin2hex(random_bytes(6)),
        'options' => ['material' => ['group' => 'Материал', 'value' => 'Оцинковка']],
    ], $attributes));
}

function yandexBuild(): SimpleXMLElement
{
    expect(app(YandexFeedRebuilder::class)->rebuild())->toBeTrue();
    $state = app(YandexFeedState::class);
    if ($token = $state->read()['scheduled_token']) {
        app(YandexFeedInvalidator::class)->releaseDispatch($token);
    }

    return simplexml_load_file($state->path());
}

test('feed endpoint serves only completed files with conditional caching and no catalog queries', function (): void {
    $this->get('/feeds/yandex.yml')->assertStatus(503);
    $this->get('/feeds/yandex.yml.gz')->assertStatus(503);
    yandexVariant();
    yandexBuild();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->get('/feeds/yandex.yml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    expect($response->streamedContent())->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
    expect(DB::getQueryLog())->toBe([]);
    expect($response->headers->has('Set-Cookie'))->toBeFalse();
    $etag = $response->headers->get('ETag');
    $modified = $response->headers->get('Last-Modified');
    expect($etag)->not->toBeNull()->and($modified)->not->toBeNull();
    $this->withHeaders(['If-None-Match' => $etag])->get('/feeds/yandex.yml')->assertStatus(304);
    $this->flushHeaders();
    $this->withHeaders(['If-Modified-Since' => $modified])->get('/feeds/yandex.yml')->assertStatus(304);
    $this->flushHeaders();
    $this->withHeaders(['If-None-Match' => '"different"', 'If-Modified-Since' => $modified])->get('/feeds/yandex.yml')->assertOk();
    DB::disableQueryLog();
});

test('conditional 304 uses only opened file metadata and a raw 200 reads the body once', function (): void {
    stream_wrapper_register('counting-yandex', CountingYandexFeedStreamWrapper::class);
    CountingYandexFeedStreamWrapper::reset(str_repeat('feed-byte-', 200_000));
    $state = Mockery::mock(YandexFeedState::class);
    $state->shouldReceive('path')->times(4)->andReturn('counting-yandex://published/yandex.yml');
    $controller = app(YandexFeedController::class);

    try {
        $modified = gmdate('D, d M Y H:i:s', 1_700_000_000).' GMT';
        $byDate = $controller(Request::create('/feeds/yandex.yml', 'GET', server: [
            'HTTP_IF_MODIFIED_SINCE' => $modified,
        ]), $state);
        expect($byDate->getStatusCode())->toBe(304)
            ->and(CountingYandexFeedStreamWrapper::$bytesRead)->toBe(0);

        $etag = $byDate->headers->get('ETag');
        $byEtag = $controller(Request::create('/feeds/yandex.yml', 'GET', server: [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]), $state);
        expect($byEtag->getStatusCode())->toBe(304)
            ->and(CountingYandexFeedStreamWrapper::$bytesRead)->toBe(0);

        $head = $controller(Request::create('/feeds/yandex.yml', 'HEAD'), $state);
        ob_start();
        $head->sendContent();
        $headOutput = ob_get_clean();
        expect($head->getStatusCode())->toBe(200)
            ->and($head->headers->get('Content-Length'))->toBe((string) strlen(CountingYandexFeedStreamWrapper::$content))
            ->and($headOutput)->toBe('')
            ->and(CountingYandexFeedStreamWrapper::$bytesRead)->toBe(0);

        $body = $controller(Request::create('/feeds/yandex.yml', 'GET'), $state);
        ob_start();
        $body->sendContent();
        $output = ob_get_clean();
        expect($output)->toBe(CountingYandexFeedStreamWrapper::$content)
            ->and(CountingYandexFeedStreamWrapper::$bytesRead)->toBe(strlen(CountingYandexFeedStreamWrapper::$content));
    } finally {
        stream_wrapper_unregister('counting-yandex');
    }
});

test('gzip endpoint serves the same validated YML with conditional caching', function (): void {
    yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    expect(is_file($state->gzipPath()))->toBeTrue()
        ->and(gzdecode(file_get_contents($state->gzipPath())))->toBe(file_get_contents($state->path()));
    $response = $this->get('/feeds/yandex.yml.gz')->assertOk()->assertHeader('Content-Type', 'application/gzip');
    expect($response->streamedContent())->toBe(file_get_contents($state->gzipPath()));
    $etag = $response->headers->get('ETag');
    $modified = $response->headers->get('Last-Modified');
    $this->withHeaders(['If-None-Match' => $etag])->get('/feeds/yandex.yml.gz')->assertStatus(304);
    $this->flushHeaders();
    $this->withHeaders(['If-Modified-Since' => $modified])->get('/feeds/yandex.yml.gz')->assertStatus(304);
});

test('each feed variant URL deep links the exact purchasable storefront variant', function (): void {
    $product = Product::factory()->create(['title' => 'Deep link product', 'slug' => 'deep-link-product']);
    $group = ProductOptionGroup::factory()->create(['title' => 'Толщина', 'code' => 'thickness', 'input_type' => 'select']);
    $firstValue = ProductOptionValue::factory()->forGroup($group)->create(['title' => '1 мм', 'code' => 'one-mm']);
    $secondValue = ProductOptionValue::factory()->forGroup($group)->create(['title' => '2 мм', 'code' => 'two-mm']);
    $first = ProductVariant::factory()->forProduct($product)->default()->create([
        'title' => 'Первый вариант', 'price' => 1111, 'stock_quantity' => 5,
    ]);
    $second = ProductVariant::factory()->forProduct($product)->create([
        'title' => 'Второй вариант', 'price' => 2222, 'stock_quantity' => 5,
    ]);
    foreach ([[$first, $firstValue], [$second, $secondValue]] as [$variant, $value]) {
        ProductVariantOptionValue::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $value->getKey(),
        ]);
    }
    $xml = yandexBuild();
    $offers = collect($xml->xpath('//offer'))->keyBy(fn ($offer): string => (string) $offer['id']);
    $firstUrl = (string) $offers['variant-'.$first->id]->url;
    $secondUrl = (string) $offers['variant-'.$second->id]->url;
    expect($firstUrl)->not->toBe($secondUrl)
        ->and($firstUrl)->toEndWith('?variant='.$first->id)
        ->and($secondUrl)->toEndWith('?variant='.$second->id)
        ->and((string) $offers['variant-'.$second->id]->price)->toBe('2222.00')
        ->and((string) $offers['variant-'.$second->id]->name)->toContain('Второй вариант')
        ->and((string) $offers['variant-'.$second->id]->xpath('param[@name="Толщина"]')[0])->toBe('2 мм');

    $landing = $this->get($secondUrl)->assertOk()
        ->assertViewHas('variant', fn (ProductVariant $variant): bool => $variant->is($second));
    $matrix = collect($landing->viewData('variantMatrix'))->keyBy('variant_id');
    expect($landing->viewData('variant')->title)->toBe('Второй вариант')
        ->and($matrix[$second->id]['price'])->toBe('2222.00')
        ->and($matrix[$second->id]['option_values'])->toContain([
            'group_id' => $group->id,
            'value_id' => $secondValue->id,
            'code' => 'two-mm',
        ])
        ->and($landing->viewData('selectedPriceLabel'))->toContain('2 222')
        ->and($landing->getContent())->toContain('value="'.$secondValue->id.'" selected');

    $cart = Cart::factory()->create();
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->post(route('cart.items.store'), ['product_variant_id' => $second->id, 'quantity' => 1])
        ->assertRedirect();
    expect($cart->items()->where('product_variant_id', $second->id)->exists())->toBeTrue();

    foreach (['variant=abc', 'variant=0', 'variant[]=1'] as $query) {
        $this->get(route('products.show', $product->slug).'?'.$query)->assertNotFound();
    }
    $foreign = yandexVariant();
    $this->get(route('products.show', $product->slug).'?variant='.$foreign->id)->assertNotFound();
    $second->update(['is_active' => false]);
    $this->get(route('products.show', $product->slug).'?variant='.$second->id)->assertNotFound();
});

test('variant deep-link URL is deterministic while offer ID ignores title slug and price', function (): void {
    $variant = yandexVariant();
    $first = yandexBuild()->shop->offers->offer;
    $firstId = (string) $first['id'];
    $firstUrl = (string) $first->url;
    $same = yandexBuild()->shop->offers->offer;
    expect((string) $same->url)->toBe($firstUrl);
    $variant->product->update(['title' => 'Changed product title', 'slug' => 'changed-product-slug']);
    $variant->update(['title' => 'Changed variant title', 'price' => 4567]);
    $changed = yandexBuild()->shop->offers->offer;
    expect((string) $changed['id'])->toBe($firstId)
        ->and((string) $changed->url)->toEndWith('/products/changed-product-slug?variant='.$variant->id)
        ->and((string) $changed->price)->toBe('4567.00');
});

test('YML contains stable categories variants prices canonical URLs structured fitments and escaped text', function (): void {
    $root = ProductCategory::factory()->create(['title' => 'Кузов & детали']);
    $child = ProductCategory::factory()->forParent($root)->create(['title' => 'Пороги']);
    $variant = yandexVariant();
    $product = $variant->product;
    $product->update([
        'product_category_id' => $child->id,
        'title' => 'Порог & "левый" 😀',
        'description' => '<b>Описание</b> & кавычки " \' UTF-8 😀 ]]> 1 < 2 > 0',
        'meta_description' => 'NOT PUBLIC DESCRIPTION',
    ]);
    ProductFitment::factory()->for($product)->create(['vehicle_generation_id' => VehicleGeneration::factory()->create()->id]);
    ProductFitment::factory()->for($product)->create(['vehicle_generation_id' => VehicleGeneration::factory()->create()->id]);
    $xml = yandexBuild();
    $offer = $xml->shop->offers->offer;
    expect($xml->getName())->toBe('yml_catalog')
        ->and((string) $xml['date'])->toBe(now()->format(DateTimeInterface::RFC3339))
        ->and((string) $xml->shop->currencies->currency['id'])->toBe('RUR')
        ->and($xml->xpath('//offer'))->toHaveCount(1)
        ->and((string) $offer['id'])->toBe('variant-'.$variant->id)
        ->and((string) $offer['available'])->toBe('true')
        ->and((string) $offer->price)->toBe('1200.00')
        ->and((string) $offer->oldprice)->toBe('1500.00')
        ->and((string) $offer->currencyId)->toBe('RUR')
        ->and((string) $offer->categoryId)->toBe((string) $child->id)
        ->and((string) $offer->url)->toBe('https://shop.example.test/products/'.$product->slug.'?variant='.$variant->id)
        ->and((string) $offer->name)->toContain('& "левый" 😀')
        ->and((string) $offer->description)->toContain('&', '😀', ']]>')->not->toContain('NOT PUBLIC DESCRIPTION')
        ->and($xml->xpath('//vendor'))->toBe([])
        ->and((string) $offer->vendorCode)->toBe($variant->sku)
        ->and($xml->xpath('//param[@name="Материал"]'))->toHaveCount(1)
        ->and($xml->xpath('//param[@name="Марка автомобиля"]'))->toHaveCount(1)
        ->and((string) $xml->xpath('//category[@id="'.$child->id.'"]')[0]['parentId'])->toBe((string) $root->id);
    $id = (string) $offer['id'];
    $product->update(['title' => 'Новое название', 'slug' => 'new-slug', 'price' => 5555]);
    $variant->update(['price' => 1300]);
    $again = yandexBuild();
    expect((string) $again->shop->offers->offer['id'])->toBe($id)
        ->and((string) $again->shop->offers->offer->price)->toBe('1300.00');
});

test('feed visibility is the storefront purchase contract', function (string $case, bool $included): void {
    $variant = yandexVariant();
    $product = $variant->product;
    match ($case) {
        'draft' => $product->update(['status' => ProductStatus::Draft]),
        'archive' => $product->update(['status' => ProductStatus::Archived]),
        'deleted' => $product->delete(),
        'inactive category' => $product->category->update(['is_active' => false]),
        'deleted category' => DB::table('product_categories')->where('id', $product->product_category_id)->update(['deleted_at' => now()]),
        'inactive parent' => (function () use ($product): void {
            $parent = ProductCategory::factory()->create();
            $product->category->update(['parent_id' => $parent->id]);
            $parent->update(['is_active' => false]);
        })(),
        'inactive variant' => $variant->update(['is_active' => false]),
        'zero price' => $variant->update(['price' => 0]),
        'out of stock' => $variant->update(['stock_status' => StockStatus::OutOfStock]),
        'zero stock' => $variant->update(['stock_quantity' => 0]),
        'preorder' => $variant->update(['stock_status' => StockStatus::PreOrder, 'stock_quantity' => 0]),
        'unlimited' => $variant->update(['stock_quantity' => null]),
        default => null,
    };
    $xml = yandexBuild();
    expect(count($xml->xpath('//offer')))->toBe($included ? 1 : 0)
        ->and(app(StorefrontProductAvailability::class)->isPurchasable($variant->fresh()))->toBe($included);
    if ($case === 'inactive parent') {
        expect(app(StorefrontProductAvailability::class)->products(Product::query())->whereKey($product)->exists())->toBeFalse();
        $this->get(route('products.show', $product->slug))->assertNotFound();
    }
})->with([
    ['active', true], ['draft', false], ['archive', false], ['deleted', false],
    ['inactive category', false], ['deleted category', false], ['inactive parent', false],
    ['inactive variant', false], ['zero price', false], ['out of stock', false],
    ['zero stock', false], ['preorder', true], ['unlimited', true],
]);

test('archive and category status changes invalidate and remove then restore offers', function (): void {
    $variant = yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    $variant->product->update(['status' => ProductStatus::Archived]);
    expect($state->status()['dirty'])->toBeTrue();
    expect(yandexBuild()->xpath('//offer'))->toBe([]);
    $variant->product->update(['status' => ProductStatus::Active]);
    expect(yandexBuild()->xpath('//offer'))->toHaveCount(1);
    $variant->product->category->update(['is_active' => false]);
    expect($state->status()['dirty'])->toBeTrue();
    expect(yandexBuild()->xpath('//offer'))->toBe([]);
    $variant->product->category->update(['is_active' => true]);
    expect(yandexBuild()->xpath('//offer'))->toHaveCount(1);
});

test('feed keeps base price without cart promo and emits oldprice only above price', function (?string $old, bool $expected): void {
    $variant = yandexVariant(['old_price' => $old, 'sku' => null]);
    $variant->product->update(['sku' => null, 'old_price' => null, 'price' => 9000]);
    PromoCode::factory()->create();
    $offer = yandexBuild()->shop->offers->offer;
    expect((string) $offer->price)->toBe('1200.00')
        ->and(isset($offer->oldprice))->toBe($expected)
        ->and(isset($offer->vendorCode))->toBeFalse();
})->with([[null, false], ['1100.00', false], ['1200.00', false], ['1500.00', true]]);

test('pictures use real public files main first and omit hidden missing default and source URLs', function (): void {
    $variant = yandexVariant();
    foreach ([['main', 9, true, true], ['gallery', 1, false, true], ['hidden', 0, false, false], ['missing', 2, false, true], ['default', 3, false, true]] as [$name, $position, $main, $visible]) {
        if ($name !== 'missing') {
            Storage::disk('public')->put($name.'.webp', 'test image bytes');
        }
        ProductImage::withoutEvents(fn () => ProductImage::query()->create([
            'product_id' => $variant->product_id, 'path' => $name.'.webp', 'disk' => 'public',
            'source_url' => 'https://import-source.example.test/'.$name, 'is_main' => $main,
            'is_visible' => $visible, 'position' => $position,
            'is_default' => $name === 'default', 'source_type' => $name === 'default' ? 'default' : 'manual',
        ]));
    }
    $pictures = array_map(fn ($picture): string => (string) $picture, yandexBuild()->xpath('//picture'));
    expect($pictures)->toBe(['https://shop.example.test/storage/main.webp', 'https://shop.example.test/storage/gallery.webp']);
    expect(file_get_contents(app(YandexFeedState::class)->path()))->not->toContain('import-source', 'placeholder');
    ProductImage::query()->where('product_id', $variant->product_id)->update(['is_visible' => false]);
    expect(app(YandexFeedState::class)->status()['dirty'])->toBeTrue();
    expect(yandexBuild()->xpath('//picture'))->toBe([]);
});

test('committed bulk quiet and variant writes invalidate while rollback does not', function (): void {
    $variant = yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    $revision = $state->read()['source_revision'];
    DB::beginTransaction();
    $variant->update(['price' => 1300]);
    expect($state->read()['source_revision'])->toBe($revision);
    DB::rollBack();
    expect($state->read()['source_revision'])->toBe($revision);
    DB::transaction(fn () => ProductVariant::query()->whereKey($variant)->update(['price' => 1400]));
    expect($state->read()['source_revision'])->toBeGreaterThan($revision);
    yandexBuild();
    $variant->product->forceFill(['title' => 'Quiet edit'])->saveQuietly();
    expect($state->status()['dirty'])->toBeTrue();
});

test('many changes coalesce into one pending job and state survives new service instances', function (): void {
    $variant = yandexVariant();
    yandexBuild();
    Queue::fake();
    foreach (range(1, 20) as $index) {
        $variant->update(['price' => 1200 + $index]);
    }
    Queue::assertPushed(RebuildYandexFeedJob::class, 1);
    $state = new YandexFeedState;
    expect($state->status()['dirty'])->toBeTrue();
    $job = Queue::pushed(RebuildYandexFeedJob::class)->first();
    app()->call([$job, 'handle']);
    expect((new YandexFeedState)->status()['dirty'])->toBeFalse();
});

test('edits during generation discard stale file and schedule the newer revision', function (): void {
    $variant = yandexVariant();
    yandexBuild();
    $path = app(YandexFeedState::class)->path();
    $old = file_get_contents($path);
    $generator = app(YandexFeedGenerator::class);
    $mock = Mockery::mock(YandexFeedGenerator::class);
    $mock->shouldReceive('generate')->once()->andReturnUsing(function (string $temporary) use ($generator, $variant): array {
        $result = $generator->generate($temporary);
        $variant->update(['price' => 7777]);

        return $result;
    });
    app()->instance(YandexFeedGenerator::class, $mock);
    expect(app(YandexFeedRebuilder::class)->rebuild())->toBeFalse()
        ->and(file_get_contents($path))->toBe($old)
        ->and(app(YandexFeedState::class)->status()['dirty'])->toBeTrue();
    app()->instance(YandexFeedGenerator::class, $generator);
    expect((string) yandexBuild()->shop->offers->offer->price)->toBe('7777.00');
});

test('generation and validation failures preserve the previous file remove temp and stay dirty', function (string $failure): void {
    yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    $old = file_get_contents($state->path());
    $oldGzip = file_get_contents($state->gzipPath());
    $class = $failure === 'generation' ? YandexFeedGenerator::class : YandexFeedValidator::class;
    $method = $failure === 'generation' ? 'generate' : 'validate';
    $mock = Mockery::mock($class);
    $mock->shouldReceive($method)->once()->andThrow(new RuntimeException('Simulated failure'));
    app()->instance($class, $mock);
    expect(fn () => app(YandexFeedRebuilder::class)->rebuild())->toThrow(RuntimeException::class)
        ->and(file_get_contents($state->path()))->toBe($old)
        ->and(file_get_contents($state->gzipPath()))->toBe($oldGzip)
        ->and(glob($state->path().'.tmp.*'))->toBe([])
        ->and($state->status()['dirty'])->toBeTrue()
        ->and($state->read()['last_error'])->toBe('Simulated failure');
    $this->get('/feeds/yandex.yml')->assertOk();
})->with(['generation', 'validation']);

test('gzip failure preserves both previous published files and dirty revision', function (): void {
    $variant = yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    $old = file_get_contents($state->path());
    $oldGzip = file_get_contents($state->gzipPath());
    $variant->update(['price' => 9876]);
    $compressor = Mockery::mock(YandexFeedCompressor::class);
    $compressor->shouldReceive('compress')->once()->andThrow(new RuntimeException('Simulated gzip failure'));
    app()->instance(YandexFeedCompressor::class, $compressor);
    expect(fn () => app(YandexFeedRebuilder::class)->rebuild())->toThrow(RuntimeException::class)
        ->and(file_get_contents($state->path()))->toBe($old)
        ->and(file_get_contents($state->gzipPath()))->toBe($oldGzip)
        ->and($state->status()['dirty'])->toBeTrue()
        ->and(glob($state->path().'.tmp.*'))->toBe([])
        ->and(glob($state->gzipPath().'.tmp.*'))->toBe([]);
});

test('active import defers all rebuilds until final images complete including bulk archive', function (): void {
    $manual = yandexVariant();
    $imported = yandexVariant();
    $archived = yandexVariant();
    yandexBuild();
    Queue::fake();
    $run = ImportRun::query()->create([
        'type' => 'catalog', 'status' => ImportRunStatus::RunningRows,
        'original_name' => 'fixture.csv', 'stored_path' => 'fixture.csv',
        'file_hash' => hash('sha256', 'yandex-fixture'),
        'total_rows' => 2, 'queued_images' => 1,
    ]);
    foreach (range(1, 20) as $index) {
        $imported->update(['price' => 2000 + $index]);
    }
    expect(app(YandexFeedRebuilder::class)->rebuild())->toBeFalse();
    Queue::assertNotPushed(RebuildYandexFeedJob::class);
    app(ImportStatusService::class)->markRowsDone($run);
    expect($run->fresh()->status)->toBe(ImportRunStatus::ProcessingImages);
    Queue::assertNotPushed(RebuildYandexFeedJob::class);
    Product::query()->whereKey($archived->product_id)->update(['status' => ProductStatus::Archived]);
    Storage::disk('public')->put('import-final.webp', 'final bytes');
    ProductImage::withoutEvents(fn () => ProductImage::query()->create([
        'product_id' => $imported->product_id, 'path' => 'import-final.webp', 'disk' => 'public',
        'source_type' => 'import', 'is_visible' => true, 'is_main' => true, 'position' => 0,
    ]));
    app(ImportStatusService::class)->imageProcessed($run);
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done);
    Queue::assertPushed(RebuildYandexFeedJob::class, 1);
    $job = Queue::pushed(RebuildYandexFeedJob::class)->first();
    app()->call([$job, 'handle']);
    $xml = simplexml_load_file(app(YandexFeedState::class)->path());
    expect($xml->xpath('//offer'))->toHaveCount(2)
        ->and((string) $xml->shop->offers->offer['id'])->toBe('variant-'.$manual->id)
        ->and((string) $xml->shop->offers->offer[1]->price)->toBe('2020.00')
        ->and((string) $xml->shop->offers->offer[1]->picture)->toEndWith('/storage/import-final.webp');
});

test('command reports counters and scheduler only dispatches for missing or dirty feed', function (): void {
    yandexVariant();
    $this->artisan('feed:yandex:rebuild')->assertSuccessful();
    $state = app(YandexFeedState::class);
    $status = $state->status();
    expect($status['offers_count'])->toBe(1)->and($status['categories_count'])->toBe(1)
        ->and($status['file_size'])->toBeGreaterThan(0)->and($status['gzip_size'])->toBeGreaterThan(0)
        ->and($status['generation_started_at'])->not->toBeNull()->and($status['generated_at'])->not->toBeNull()
        ->and($status['dirty'])->toBeFalse();
    $this->artisan('feed:yandex:rebuild --status')->assertSuccessful();
    app(YandexFeedInvalidator::class)->releaseDispatch($status['scheduled_token'] ?? '');
    Queue::fake();
    app(YandexFeedInvalidator::class)->dispatchIfNeeded();
    Queue::assertNothingPushed();
    unlink($state->gzipPath());
    app(YandexFeedInvalidator::class)->dispatchIfNeeded();
    Queue::assertPushed(RebuildYandexFeedJob::class, 1);
    expect(collect(app(Schedule::class)->events())->firstWhere('description', 'yandex-feed-refresh')->expression)->toBe('*/10 * * * *');
});

test('generation lock prevents a second active rebuild and scheduler recovers expired dispatch', function (): void {
    yandexVariant();
    $state = app(YandexFeedState::class);
    $lock = fopen($state->directory().'/generation.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        expect(app(YandexFeedRebuilder::class)->rebuild())->toBeFalse()
            ->and(is_file($state->path()))->toBeFalse();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    Queue::fake();
    $state->locked(function (array &$state): void {
        $state['scheduled_until'] = time() - 1;
    });
    app(YandexFeedInvalidator::class)->dispatchIfNeeded();
    Queue::assertPushed(RebuildYandexFeedJob::class, 1);
    app()->call([Queue::pushed(RebuildYandexFeedJob::class)->first(), 'handle']);
    expect($state->status()['dirty'])->toBeFalse();
});

test('variant price wins while old price and SKU fall back and structured option writes invalidate', function (): void {
    $variant = yandexVariant(['old_price' => null, 'sku' => null]);
    $variant->product->update(['price' => 4321.25, 'old_price' => 5000, 'sku' => 'REAL-PRODUCT-SKU']);
    $offer = yandexBuild()->shop->offers->offer;
    expect((string) $offer->price)->toBe('1200.00')->and((string) $offer->oldprice)->toBe('5000.00')
        ->and((string) $offer->vendorCode)->toBe('REAL-PRODUCT-SKU');
    $value = ProductOptionValue::factory()->create();
    yandexBuild();
    $variant->optionValues()->attach($value, ['product_option_group_id' => $value->product_option_group_id]);
    expect(app(YandexFeedState::class)->status()['dirty'])->toBeTrue();
    yandexBuild();
    $value->update(['is_active' => false]);
    expect(app(YandexFeedState::class)->status()['dirty'])->toBeTrue()
        ->and(yandexBuild()->xpath('//offer'))->toBe([]);
});

test('deleted ancestors and cycles never leave orphan feed categories', function (): void {
    $parent = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->forParent($parent)->create();
    $variant = yandexVariant();
    $variant->product->update(['product_category_id' => $child->id]);
    DB::table('product_categories')->where('id', $parent->id)->update(['deleted_at' => now()]);
    expect(yandexBuild()->xpath('//offer'))->toBe([]);
    DB::table('product_categories')->where('id', $parent->id)->update(['deleted_at' => null, 'parent_id' => $child->id]);
    $xml = yandexBuild();
    expect($xml->xpath('//offer'))->toBe([])
        ->and($xml->xpath('//category[@id="'.$child->id.'"]'))->toBe([])
        ->and(app(StorefrontProductAvailability::class)->isPurchasable($variant->fresh()))->toBeFalse();
});

test('validator rejects malformed and semantically invalid complete XML', function (string $fault): void {
    $variant = yandexVariant();
    $variant->product->update(['description' => 'Valid description']);
    yandexBuild();
    $state = app(YandexFeedState::class);
    $xml = file_get_contents($state->path());
    $invalid = match ($fault) {
        'malformed' => substr($xml, 0, -25),
        'price' => str_replace('<price>1200.00</price>', '<price>0.00</price>', $xml),
        'url' => preg_replace('~<url>[^<]+</url>~', '<url>/relative</url>', $xml),
        'url length' => preg_replace('~(<offer .*?<url>).*?(</url>)~s', '$1https://shop.example.test/'.str_repeat('x', 512).'$2', $xml),
        'name length' => preg_replace('~(<offer .*?<name>).*?(</name>)~s', '$1'.str_repeat('Я', 151).'$2', $xml),
        'description length' => preg_replace('~(<offer .*?<description>).*?(</description>)~s', '$1'.str_repeat('Я', 3001).'$2', $xml),
        'category' => preg_replace('~<categoryId>\d+</categoryId>~', '<categoryId>999999</categoryId>', $xml),
        'duplicate offer' => preg_replace('~(<offer .*?</offer>)~s', '$1$1', $xml),
        'duplicate category' => preg_replace('~(<category .*?</category>)~s', '$1$1', $xml),
        'orphan' => preg_replace('~<category id="~', '<category parentId="999999" id="', $xml),
        'currency' => str_replace('<currencyId>RUR</currencyId>', '<currencyId>RUB</currencyId>', $xml),
        'container' => str_replace(['<offers>', '</offers>'], ['<categories>', '</categories>'], $xml),
        'DTD' => str_replace('<yml_catalog', '<!DOCTYPE yml_catalog SYSTEM "https://example.test/never-fetch.dtd"><yml_catalog', $xml),
    };
    $path = $state->directory().'/invalid.xml';
    file_put_contents($path, $invalid);
    expect(fn () => app(YandexFeedValidator::class)->validate($path))->toThrow(RuntimeException::class)
        ->and(file_get_contents($state->path()))->toBe($xml);
})->with([
    'malformed', 'price', 'url', 'url length', 'name length', 'description length',
    'category', 'duplicate offer', 'duplicate category', 'orphan', 'currency', 'container', 'DTD',
]);

test('catalog iteration stays chunked without per offer relation queries', function (): void {
    config()->set('yandex-feed.chunk_size', 20);
    $product = Product::factory()->create();
    ProductVariant::factory()->count(105)->for($product)->create(['price' => 1200, 'stock_status' => StockStatus::InStock, 'stock_quantity' => 5]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $xml = yandexBuild();
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($xml->xpath('//offer'))->toHaveCount(105)
        ->and($queries->count())->toBeLessThan(100)
        ->and($queries->filter(fn (array $query) => str_contains($query['query'], 'from "product_variants"') && str_contains($query['query'], 'limit 20'))->count())->toBe(6);
});

test('unfinished paused or failed import never publishes partial rows and later success unblocks', function (ImportRunStatus $status): void {
    $variant = yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    $old = file_get_contents($state->path());
    Queue::fake();
    $run = ImportRun::query()->create([
        'type' => 'catalog', 'status' => $status, 'started_at' => now(),
        'original_name' => 'fixture.csv', 'stored_path' => 'fixture.csv',
        'file_hash' => hash('sha256', 'yandex-interrupted'),
    ]);
    $variant->update(['price' => 2000]);
    expect(app(YandexFeedRebuilder::class)->rebuild())->toBeFalse()
        ->and(file_get_contents($state->path()))->toBe($old);
    Queue::assertNotPushed(RebuildYandexFeedJob::class);
    if ($status === ImportRunStatus::Paused) {
        app(ImportStatusService::class)->resume($run);
        app(ImportStatusService::class)->markRowsDone($run);
    } else {
        $run = $run->replicate();
        $run->status = ImportRunStatus::RunningRows;
        $run->save();
        app(ImportStatusService::class)->markRowsDone($run);
    }
    Queue::assertPushed(RebuildYandexFeedJob::class, 1);
    expect((string) yandexBuild()->shop->offers->offer->price)->toBe('2000.00');
})->with([ImportRunStatus::Paused, ImportRunStatus::Failed, ImportRunStatus::Canceled]);

test('import starting inside generation discards that build and final completion rebuilds', function (): void {
    yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    $old = file_get_contents($state->path());
    $generator = app(YandexFeedGenerator::class);
    $run = null;
    $mock = Mockery::mock(YandexFeedGenerator::class);
    $mock->shouldReceive('generate')->once()->andReturnUsing(function (string $temporary) use ($generator, &$run): array {
        $metrics = $generator->generate($temporary);
        $run = ImportRun::query()->create([
            'type' => 'catalog', 'status' => ImportRunStatus::RunningRows, 'started_at' => now(),
            'original_name' => 'fixture.csv', 'stored_path' => 'fixture.csv', 'file_hash' => hash('sha256', 'yandex-start-race'),
        ]);

        return $metrics;
    });
    app()->instance(YandexFeedGenerator::class, $mock);
    expect(app(YandexFeedRebuilder::class)->rebuild())->toBeFalse()
        ->and(file_get_contents($state->path()))->toBe($old)
        ->and($state->status()['dirty'])->toBeTrue();
    app()->instance(YandexFeedGenerator::class, $generator);
    app(ImportStatusService::class)->markRowsDone($run);
    yandexBuild();
    expect($state->status()['dirty'])->toBeFalse();
});

test('state persistence failure after rename restores previous feed and command reports failure', function (): void {
    $variant = yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    $old = file_get_contents($state->path());
    $oldGzip = file_get_contents($state->gzipPath());
    $variant->update(['price' => 3333]);
    $failed = false;
    $mock = Mockery::mock(YandexFeedState::class)->makePartial();
    $mock->shouldReceive('locked')->andReturnUsing(function (Closure $callback, bool $write = true) use ($state, &$failed): mixed {
        return $state->locked(function (array &$data) use ($callback, &$failed): mixed {
            $result = $callback($data);
            if ($result === true && ! $failed) {
                $failed = true;
                throw new RuntimeException('Simulated state write failure');
            }

            return $result;
        }, $write);
    });
    app()->instance(YandexFeedState::class, $mock);
    $this->artisan('feed:yandex:rebuild')->assertFailed();
    expect(file_get_contents($state->path()))->toBe($old)
        ->and(file_get_contents($state->gzipPath()))->toBe($oldGzip)
        ->and($state->status()['dirty'])->toBeTrue()
        ->and($state->read()['last_error'])->toBe('Simulated state write failure')
        ->and(glob($state->path().'.tmp.*'))->toBe([]);
});

test('catalog date is generation start while generated_at is successful publication', function (): void {
    config()->set('app.timezone', 'Europe/Moscow');
    $start = Carbon::parse('2026-09-07 12:34:56', 'Europe/Moscow');
    $published = $start->copy()->addMinutes(8);
    Carbon::setTestNow($start);
    try {
        yandexVariant();
        $generator = app(YandexFeedGenerator::class);
        $mock = Mockery::mock(YandexFeedGenerator::class);
        $mock->shouldReceive('generate')->once()->andReturnUsing(function (string $path) use ($generator, $published): array {
            $metrics = $generator->generate($path);
            Carbon::setTestNow($published);

            return $metrics;
        });
        app()->instance(YandexFeedGenerator::class, $mock);
        $xml = yandexBuild();
        $status = app(YandexFeedState::class)->status();
        expect((string) $xml['date'])->toBe('2026-09-07T12:34:56+03:00')
            ->and($status['generation_started_at'])->toBe($start->toIso8601String())
            ->and($status['generated_at'])->toBe($published->copy()->utc()->toIso8601String());
    } finally {
        Carbon::setTestNow();
    }
});

test('validator rejects a future RFC3339 catalog date', function (): void {
    config()->set('app.timezone', 'Europe/Moscow');
    $start = Carbon::parse('2026-09-07 12:00:00', 'Europe/Moscow');
    Carbon::setTestNow($start);
    try {
        yandexVariant();
        yandexBuild();
        $state = app(YandexFeedState::class);
        $xml = simplexml_load_file($state->path());
        $xml['date'] = $start->copy()->addSecond()->format(DateTimeInterface::RFC3339);
        $path = $state->directory().'/future.xml';
        $xml->asXML($path);

        expect(fn () => app(YandexFeedValidator::class)->validate($path))
            ->toThrow(RuntimeException::class, 'Future generation date');
    } finally {
        Carbon::setTestNow();
    }
});

test('name and description limits preserve UTF-8 boundary characters', function (string $field, int $length, int $expected): void {
    $variant = yandexVariant(['title' => null]);
    $value = str_repeat($field === 'name' ? 'Я' : '😀', $length);
    $variant->product->update($field === 'name' ? ['title' => $value] : ['description' => $value]);
    $offer = yandexBuild()->shop->offers->offer;
    expect(mb_strlen((string) $offer->{$field}))->toBe($expected)
        ->and(mb_check_encoding((string) $offer->{$field}, 'UTF-8'))->toBeTrue();
})->with([
    ['name', 149, 149], ['name', 150, 150], ['name', 151, 150],
    ['description', 2999, 2999], ['description', 3000, 3000], ['description', 3001, 3000],
]);

test('overlong deep-link URL is skipped rather than emitted outside Yandex limit', function (): void {
    config()->set('app.url', 'https://shop.example.test/'.str_repeat('catalog/', 55));
    yandexVariant();
    yandexBuild();
    $status = app(YandexFeedState::class)->status();
    expect($status['offers_count'])->toBe(0)
        ->and($status['skip_reasons']['url_too_long'])->toBe(1);
});

test('feed queue timeout and retry relation cover measured catalog growth safely', function (): void {
    $worker = app('queue.worker');
    expect($worker)->toBeInstanceOf(Worker::class);

    $job = new RebuildYandexFeedJob('test-token');
    $queuedJob = Mockery::mock(Job::class);
    $queuedJob->shouldReceive('timeout')->twice()->andReturn($job->timeout);
    $timeoutForJob = new ReflectionMethod(Worker::class, 'timeoutForJob');
    $timeoutForJob->setAccessible(true);

    expect($job->timeout)->toBe(1200)
        ->and($job->timeout)->toBe(RebuildYandexFeedJob::TIMEOUT)
        ->and($job->tries)->toBe(3)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and(config('queue.connections.yandex-feed.retry_after'))->toBe(1500)
        ->and(config('queue.connections.yandex-feed.retry_after'))->toBeGreaterThan($job->timeout)
        ->and($timeoutForJob->invoke(
            $worker,
            $queuedJob,
            new WorkerOptions(timeout: 600),
        ))->toBe(1200);
});

test('dedicated feed connection preserves the existing database queue lease and worker queues', function (): void {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect(config('queue.connections.database.retry_after'))->toBe(660)
        ->and(config('queue.connections.database.queue'))->toBe('default')
        ->and(config('queue.connections.yandex-feed.driver'))->toBe('database')
        ->and(config('queue.connections.yandex-feed.connection'))->toBe(config('queue.connections.database.connection'))
        ->and(config('queue.connections.yandex-feed.table'))->toBe(config('queue.connections.database.table'))
        ->and(config('queue.connections.yandex-feed.queue'))->toBe('yandex-feed')
        ->and(config('queue.connections.yandex-feed.retry_after'))->toBe(1500)
        ->and($compose)->toContain('queue:work --queue=default,imports,imports-images --sleep=3 --tries=3 --timeout=600')
        ->and($compose)->toContain('queue:work yandex-feed --queue=yandex-feed --sleep=3 --tries=3 --timeout=600');
});

test('feed rebuild dispatches only to its dedicated connection and queue', function (): void {
    app(YandexFeedInvalidator::class)->dispatchIfNeeded();

    Queue::assertPushed(RebuildYandexFeedJob::class, function (RebuildYandexFeedJob $job): bool {
        return $job->connection === 'yandex-feed' && $job->queue === 'yandex-feed';
    });
});

test('unsafe queue retry interval refuses to schedule a feed rebuild', function (): void {
    config()->set('queue.connections.yandex-feed.retry_after', RebuildYandexFeedJob::TIMEOUT);

    expect(fn () => app(YandexFeedInvalidator::class)->dispatchIfNeeded())
        ->toThrow(RuntimeException::class, 'retry_after must exceed its job timeout');
});

test('terminal queue failure records the error and forces a dirty rebuild', function (): void {
    yandexVariant();
    yandexBuild();
    $state = app(YandexFeedState::class);
    expect($state->status()['dirty'])->toBeFalse();

    (new RebuildYandexFeedJob('failed-token'))->failed(new RuntimeException('timed out'));

    $status = $state->status();
    expect($status['dirty'])->toBeTrue()
        ->and($status['source_revision'])->toBe($status['generated_revision'] + 1)
        ->and($status['last_error'])->toContain('timed out')
        ->and(file_get_contents($state->path()))->not->toBe('')
        ->and(gzdecode(file_get_contents($state->gzipPath())))->toBe(file_get_contents($state->path()));
});

test('next exclusive rebuild cleans orphaned timeout temporary files', function (): void {
    yandexVariant();
    $state = app(YandexFeedState::class);
    file_put_contents($state->path().'.tmp.timed-out', 'partial raw');
    file_put_contents($state->gzipPath().'.tmp.timed-out', 'partial gzip');
    yandexBuild();
    expect(glob($state->path().'.tmp.*'))->toBe([])
        ->and(glob($state->gzipPath().'.tmp.*'))->toBe([]);
});
