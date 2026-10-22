<?php

use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Import\ImportImageDownloader;
use App\Services\Media\ImageDownloadService;
use App\Services\Media\ImageProcessingService;
use App\Services\Media\VehicleImageNotFound;
use App\Services\Media\VehicleImageQuality;
use App\Services\Media\VehicleImageReview;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

function automaticRedownloadVehicle(): VehicleGeneration
{
    static $bytes;
    $bytes ??= lightReviewFixture();
    $g = photoVehicle();
    $g->model->make->forceFill(['title' => 'Example'.$g->id, 'norm_key' => 'example'.$g->id])->save();
    $g->model->forceFill(['title' => 'Car'.$g->id, 'norm_key' => 'car'.$g->id])->save();
    $path = 'uploads/vehicles/generations/'.$g->id.'/old.webp';
    Storage::disk('public')->put($path, $bytes);
    Storage::disk('public')->put($path.'.thumb.webp', $bytes);
    DB::table('vehicle_generations')->where('id', $g->id)->update(['image' => $path,
        'image_source_url' => 'https://images.example.test/old-'.$g->id.'.jpg', 'image_checksum' => hash('sha256', $bytes),
        'image_conversions' => json_encode(['thumb' => ['disk' => 'public', 'path' => $path.'.thumb.webp']])]);

    return $g->fresh();
}

function redownloadWorkbook(array $generations): string
{
    $writer = new Writer;
    $path = Storage::disk('local')->path('redownload-'.uniqid().'.xlsx');
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(array_fill(0, 6, null)));
    $writer->addRow(Row::fromValues(['Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Фото']));
    foreach ($generations as $g) {
        $v = app(VehicleImageQuality::class)->vehicle($g->fresh());
        $writer->addRow(Row::fromValues([$v['make'], $v['model'], $v['generation'], $v['years'], $v['body'], 'https://images.example.test/fresh-'.$g->id.'.jpg']));
    }
    $writer->close();

    return $path;
}

test('full image redownload backs up bindings and thumbnails then replaces even an accepted automatic photo', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $g = automaticRedownloadVehicle();
    $before = $g->getRawOriginal();
    $p = Product::factory()->create();
    $variant = ProductVariant::factory()->forProduct($p)->create(['sku' => 'UNCHANGED-REDOWNLOAD', 'price' => 1234]);
    DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $g->id, 'is_primary' => true]);
    $productBefore = $p->fresh()->getRawOriginal();
    $variantBefore = $variant->fresh()->getRawOriginal();
    $fitments = DB::table('product_fitments')->get()->toJson();
    expect(app(VehicleImageQuality::class)->stored($g)['state'])->toBe('accepted');
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: redownloadWorkbook([$g]), redownloadAll: true);
    expect($plan['entries'][$g->id]['action'])->toBe('redownload')->and($plan['backup']['files'])->toHaveCount(2)
        ->and($plan['entries'][$g->id]['previous']['image'])->toBe($g->image);
    foreach ($plan['backup']['files'] as $file) {
        expect(Storage::disk('local')->get($file['backup']))->toBe(Storage::disk('public')->get($file['path']));
    }
    Http::assertNothingSent();
    Http::fake(['https://images.example.test/fresh-'.$g->id.'.jpg' => Http::response(lightReviewFixture(), 200, ['Content-Type' => 'image/png'])]);
    expect($service->apply($plan, $plan['seal']))->toBe([$g->id => 'downloaded']);
    expect($g->fresh()->image)->not->toBe($g->image)->and($g->fresh()->image_source_url)->toBe('https://images.example.test/fresh-'.$g->id.'.jpg');
    expect($service->summary($plan))->toBe(['downloaded' => 1, 'rejected' => 0, 'absent' => 0, 'unchanged' => 0, 'pending' => 0]);
    expect(Arr::except($g->fresh()->getRawOriginal(), ['image', 'image_source_url', 'image_checksum', 'image_conversions', 'updated_at']))
        ->toBe(Arr::except($before, ['image', 'image_source_url', 'image_checksum', 'image_conversions', 'updated_at']));
    expect($p->fresh()->getRawOriginal())->toBe($productBefore)->and($variant->fresh()->getRawOriginal())->toBe($variantBefore)
        ->and(DB::table('product_fitments')->get()->toJson())->toBe($fitments)->and(ImportRun::count())->toBe(0);
    Storage::disk('public')->assertExists($g->image);
    Storage::disk('public')->assertExists($g->image.'.thumb.webp');
    Http::assertSentCount(1);
    expect($service->apply($plan, $plan['seal']))->toBe([]);
    Http::assertSentCount(1);
});

test('full redownload rechecks identical previously accepted bytes and leaves rejected results empty', function (): void {
    $reject = false;
    fake_vehicle_photo_verifier(function ($r) use (&$reject) {
        return test_vehicle_verdict($r, $reject ? ['verdict' => 'reject', 'reason' => 'wrong_vehicle'] : []);
    });
    $g = automaticRedownloadVehicle();
    $bytes = Storage::disk('public')->get($g->image);
    expect(app(VehicleImageQuality::class)->stored($g, true)['state'])->toBe('accepted');
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: redownloadWorkbook([$g]), redownloadAll: true);
    $reject = true;
    Http::fake(['https://images.example.test/fresh-'.$g->id.'.jpg' => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);
    expect($service->apply($plan, $plan['seal']))->toBe([$g->id => 'rejected'])->and($g->fresh()->image)->toBeNull()
        ->and($service->summary($plan)['rejected'])->toBe(1);
    Http::assertSentCount(2); // After resetting the fake: fresh download and fresh validation of the same hash.
    Storage::disk('public')->assertExists($g->image);
});

test('full redownload reports missing sources and never falls back to a saved old URL', function (): void {
    $g = automaticRedownloadVehicle();
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: redownloadWorkbook([$g]), redownloadAll: true);
    Http::fake(['https://images.example.test/fresh-'.$g->id.'.jpg' => Http::response('', 404)]);
    expect($service->apply($plan, $plan['seal']))->toBe([$g->id => 'absent'])->and($g->fresh()->image)->toBeNull()
        ->and($service->summary($plan)['absent'])->toBe(1);
    Http::assertSentCount(1);
    Storage::disk('public')->assertExists($g->image);
});

test('full redownload preserves manual protected unknown-origin and unresolved-Excel images', function (): void {
    $manual = automaticRedownloadVehicle();
    DB::table('vehicle_generations')->where('id', $manual->id)->update(['image_source_url' => null]);
    $unknown = automaticRedownloadVehicle();
    DB::table('vehicle_generations')->where('id', $unknown->id)->update(['image_checksum' => null]);
    $outside = automaticRedownloadVehicle();
    $protected = automaticRedownloadVehicle();
    $product = Product::factory()->create();
    DB::table('product_fitments')->insert(['product_id' => $product->id, 'vehicle_generation_id' => $protected->id, 'is_primary' => true]);
    $run = ImportRun::factory()->create();
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$product->id => 'hold'], 2, str_repeat('a', 64)));
    $before = VehicleGeneration::orderBy('id')->get()->toJson();
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: redownloadWorkbook([$manual, $unknown, $protected]), redownloadAll: true);
    expect(array_column($plan['entries'], 'preservation'))->toBe(['manual_image', 'unknown_origin', 'outside_resolved_excel_scope', 'permanent_protection'])
        ->and($service->apply($plan, $plan['seal'], allBatches: true))->toBe([])->and($service->summary($plan)['unchanged'])->toBe(4)
        ->and(VehicleGeneration::orderBy('id')->get()->toJson())->toBe($before)->and(DB::table('catalog_import_protections')->count())->toBe(1);
    Http::assertNothingSent();
});

test('full redownload refuses a damaged rollback copy before downloading or changing bindings', function (): void {
    $g = automaticRedownloadVehicle();
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: redownloadWorkbook([$g]), redownloadAll: true);
    $file = array_values($plan['backup']['files'])[0];
    Storage::disk('local')->put($file['backup'], 'damaged');
    expect(fn () => $service->apply($plan, $plan['seal']))->toThrow(RuntimeException::class)
        ->and($g->fresh()->image)->toBe($g->image);
    Http::assertNothingSent();
});

test('full redownload requires an Excel and separate confirmation while the CLI can run sequential batches', function (): void {
    $a = automaticRedownloadVehicle();
    $b = automaticRedownloadVehicle();
    $service = app(VehicleImageReview::class);
    expect(fn () => $service->plan(redownloadAll: true))->toThrow(RuntimeException::class);
    $this->artisan('vehicle-images:review', ['--redownload-all' => true, '--xlsx' => redownloadWorkbook([$a, $b]), '--output' => 'all-redownload.json'])->assertSuccessful();
    $path = Storage::disk('local')->path('all-redownload.json');
    $plan = json_decode(file_get_contents($path), true);
    $this->artisan('vehicle-images:reprocess', ['plan' => $path])->assertFailed();
    Http::assertNothingSent();
    Http::fake(['https://images.example.test/fresh-*' => Http::response('', 404)]);
    $this->artisan('vehicle-images:reprocess', ['plan' => $path, '--confirm' => $plan['seal'], '--limit' => 1, '--all-batches' => true])
        ->expectsOutputToContain('"processed_this_run":2')->assertSuccessful();
    expect($service->summary($plan))->toBe(['downloaded' => 0, 'rejected' => 0, 'absent' => 2, 'unchanged' => 0, 'pending' => 0]);
    Http::assertSentCount(2);
});

test('full redownload caps a batch at fifty and resumes without downloading completed records again', function (): void {
    $generations = [];
    foreach (range(1, 51) as $n) {
        $generations[] = automaticRedownloadVehicle();
    }
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: redownloadWorkbook($generations), redownloadAll: true);
    Http::fake(['https://images.example.test/fresh-*' => Http::response('', 404)]);
    expect($service->apply($plan, $plan['seal'], 999))->toHaveCount(50)->and($service->summary($plan)['pending'])->toBe(1);
    expect($service->apply($plan, $plan['seal'], allBatches: true))->toHaveCount(1)->and($service->summary($plan)['absent'])->toBe(51);
    Http::assertSentCount(51);
});

test('full redownload stops on a local image write failure rather than clearing a good old photo', function (): void {
    $g = automaticRedownloadVehicle();
    $this->mock(ImageProcessingService::class, function ($mock): void {
        $mock->shouldReceive('processBinary')->once()->andThrow(new RuntimeException('disk full'));
    });
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: redownloadWorkbook([$g]), redownloadAll: true);
    Http::fake(['https://images.example.test/fresh-'.$g->id.'.jpg' => Http::response(lightReviewFixture(), 200, ['Content-Type' => 'image/png'])]);
    expect(fn () => $service->apply($plan, $plan['seal']))->toThrow(RuntimeException::class, 'disk full')
        ->and($g->fresh()->image)->toBe($g->image)->and($service->summary($plan)['pending'])->toBe(1);
});

test('full redownload stops before the next batch if the approved Excel changed', function (): void {
    $a = automaticRedownloadVehicle();
    $b = automaticRedownloadVehicle();
    $xlsx = redownloadWorkbook([$a, $b]);
    $service = app(VehicleImageReview::class);
    $plan = $service->plan(xlsx: $xlsx, redownloadAll: true);
    Http::fake(['https://images.example.test/fresh-*' => Http::response('', 404)]);
    expect(fn () => $service->apply($plan, $plan['seal'], 1, true, fn () => file_put_contents($xlsx, 'changed')))->toThrow(RuntimeException::class)
        ->and($a->fresh()->image)->toBeNull()->and($b->fresh()->image)->toBe($b->image);
    Http::assertSentCount(1);
});

beforeEach(function (): void {
    if (getenv('VEHICLE_IMAGE_MYSQL_TEST') === '1') {
        expect(DB::getDriverName())->toBe('mysql')
            ->and(DB::connection()->getConfig('host'))->toBe('dvashop_safe_partial_mysql_fix')
            ->and(DB::connection()->getDatabaseName())->toBe('dvashop_guard_test');
    }
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
});

function photoVehicle(array $attributes = []): VehicleGeneration
{
    return VehicleGeneration::factory()->create($attributes + ['title' => '1', 'years_label' => '2000-2005', 'body' => 'Седан 4 дв.']);
}

function storedAutoPhoto(?string $bytes = null): VehicleGeneration
{
    Storage::disk('public')->put('old.webp', $bytes ?? test_image_binary('webp', 640, 360));

    $g = photoVehicle();
    DB::table('vehicle_generations')->where('id', $g->id)->update(['image' => 'old.webp', 'image_source_url' => 'https://images.example.test/old.jpg', 'image_checksum' => 'old']);

    return $g->fresh();
}

/** Synthetic photometry fixtures, never evidence that a car or generation was recognized. */
function lightReviewFixture(string $kind = 'complex'): string
{
    $im = imagecreatetruecolor(640, 360);
    for ($y = 0; $y < 360; $y++) {
        for ($x = 0; $x < 640; $x++) {
            $c = $kind === 'ambiguous'
                ? ((int) ($x / 80) * 30 << 16) | ((int) ($x / 80) * 30 << 8) | ((int) ($x / 80) * 30)
                : (((int) (100 + 50 * sin($x / 25) + 30 * cos($y / 17)) << 16)
                    | ((int) (110 + 45 * cos($x / 33) + 20 * sin($y / 21)) << 8) | (int) (90 + 50 * sin(($x + $y) / 35)));
            imagesetpixel($im, $x, $y, $c);
        }
    }
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

test('light mode rejects flat graphics without a site blacklist or AI', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $quality = app(VehicleImageQuality::class);
    $g = photoVehicle();
    foreach (['png', 'jpeg', 'webp'] as $format) {
        expect($quality->assess($g, test_image_binary($format, 1200, 630)))->toMatchArray([
            'state' => 'rejected', 'reason' => 'flat_graphic_or_placeholder', 'validation_level' => 'light', 'vehicle_match_verified' => false,
        ]);
    }
    expect($quality->assess($g, test_image_binary('png', 180, 180))['reason'])->toBe('invalid_raster_or_dimensions')
        ->and($quality->assess($g, 'not an image')['state'])->toBe('rejected');
    Http::assertNothingSent();
});

test('light photo screening preserves a good existing file but never claims vehicle recognition', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $g = storedAutoPhoto(lightReviewFixture());
    $review = app(VehicleImageQuality::class)->stored($g);
    expect($review)->toMatchArray(['state' => 'accepted', 'reason' => 'photo_like_light_checks', 'vehicle_match_verified' => false]);
    $again = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, 'https://images.example.test/new.png');
    expect($again->image)->toBe('old.webp')->and(app(VehicleImageReview::class)->plan()['entries'][$g->id]['action'])->toBe('keep');
    Http::assertNothingSent();
});

test('light mode rejects a bad candidate then downloads the next on a matching vehicle page', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $g = photoVehicle();
    $g->load('model.make');
    $title = htmlspecialchars($g->model->make->title.' '.$g->model->title);
    Http::fake([
        'https://www.drom.ru/car' => Http::response('<title>'.$title.'</title><script type="application/ld+json">{"@type":"Car","image":["/first.png","/second.png"]}</script>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'https://www.drom.ru/first.png' => Http::response(test_image_binary('png', 1200, 630), 200, ['Content-Type' => 'image/png']),
        'https://www.drom.ru/second.png' => Http::response(lightReviewFixture(), 200, ['Content-Type' => 'image/png']),
    ]);
    $saved = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, 'https://www.drom.ru/car');
    expect(app(VehicleImageQuality::class)->stored($saved)['state'])->toBe('accepted');
    Http::assertSentCount(3);
});

test('a generic page and a logo URL cannot supply a light-approved vehicle image', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    Http::fake(['https://auto.ru/' => Http::response('<title>Marketplace</title><meta property="og:image" content="https://auto.ru/splash.png">', 200, ['Content-Type' => 'text/html'])]);
    $g = photoVehicle();
    expect(fn () => app(ImageDownloadService::class)->downloadVehicle($g, 'https://auto.ru/', 'test'))->toThrow(VehicleImageNotFound::class);
    expect(fn () => app(ImageDownloadService::class)->downloadVehicle($g, 'https://other.example.test/images/logo.webp', 'test'))->toThrow(VehicleImageNotFound::class);
    Http::assertSentCount(1);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('enabling a visual verifier invalidates lightweight approval without silent fallback', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $g = storedAutoPhoto(lightReviewFixture());
    $quality = app(VehicleImageQuality::class);
    expect($quality->stored($g)['state'])->toBe('accepted');
    config(['vehicle_images.verifier_url' => 'https://vision.example.test/check']);
    Http::fake(['https://vision.example.test/check' => Http::response('', 503)]);
    expect($quality->stored($g, true)['state'])->toBe('unverified');
    Storage::disk('public')->assertExists('old.webp');
});

test('an explicit incompatible year range in the page title blocks its candidates', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $g = photoVehicle(['years_label' => '2000-2005']);
    $g->load('model.make');
    Http::fake(['https://auto.ru/car' => Http::response('<title>'.htmlspecialchars($g->model->make->title.' '.$g->model->title).' 2015–2020</title><meta property="og:image" content="/car.png">', 200, ['Content-Type' => 'text/html'])]);
    expect(fn () => app(ImageDownloadService::class)->downloadVehicle($g, 'https://auto.ru/car', 'test'))->toThrow(VehicleImageNotFound::class);
    Http::assertSentCount(1);
});

test('vehicle photograph needs positive bound visual proof and a good photo is reused across source changes', function (): void {
    fake_vehicle_photo_verifier();
    Http::fake(['https://images.example.test/car.png' => Http::response(test_image_binary('png', 800, 500), 200, ['Content-Type' => 'image/png'])]);
    $g = photoVehicle();
    $before = $g->fresh()->getRawOriginal();
    $saved = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, 'https://images.example.test/car.png');
    expect(app(VehicleImageQuality::class)->stored($saved)['state'])->toBe('accepted');
    $requests = Http::recorded()->count();
    $files = Storage::disk('public')->allFiles();
    $again = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($saved, 'https://images.example.test/different.jpg');
    expect($again->image)->toBe($saved->image)->and(Http::recorded()->count())->toBe($requests)
        ->and(Storage::disk('public')->allFiles())->toBe($files)
        ->and(Arr::except($again->getRawOriginal(), ['image', 'image_source_url', 'image_checksum', 'image_conversions', 'updated_at']))
        ->toBe(Arr::except($before, ['image', 'image_source_url', 'image_checksum', 'image_conversions', 'updated_at']));
    Queue::assertNothingPushed();
});

test('HTML vehicle metadata never approves a logo and later verified candidates are tried', function (): void {
    $logo = test_image_binary('png', 600, 400);
    $photo = test_image_binary('png', 900, 500);
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, $r['image_sha256'] === hash('sha256', $logo) ? ['verdict' => 'reject', 'reason' => 'logo'] : []));
    Http::fake([
        'https://www.drom.ru/car' => Http::response('<script type="application/ld+json">{"@type":"Car","image":["/first.png","/photo.png"]}</script>', 200, ['Content-Type' => 'text/html']),
        'https://www.drom.ru/first.png' => Http::response($logo, 200, ['Content-Type' => 'image/png']),
        'https://www.drom.ru/photo.png' => Http::response($photo, 200, ['Content-Type' => 'image/png']),
    ]);
    $g = app(ImportImageDownloader::class)->downloadVehicleGenerationImage(photoVehicle(), 'https://www.drom.ru/car');
    expect($g->image_checksum)->toBe(hash('sha256', $photo))->and(Storage::disk('public')->allFiles())->toHaveCount(2);
    Http::assertSent(fn ($r) => $r->url() === 'https://www.drom.ru/first.png');
    Http::assertSent(fn ($r) => $r->url() === 'https://www.drom.ru/photo.png');
});

test('HTTP200 image and a high score cannot replace required visual and applicability evidence', function (array $response): void {
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, $response));
    $g = photoVehicle();
    $assessment = app(VehicleImageQuality::class)->assess($g, test_image_binary('png', 800, 500));
    expect($assessment['state'])->not->toBe('accepted')->and($g->fresh()->image)->toBeNull();
})->with([
    [['verdict' => 'reject', 'reason' => 'advertisement']],
    [['verdict' => 'reject', 'reason' => 'wrong_vehicle']],
    [['checks' => ['matches_generation' => false]]],
    [['checks' => ['matches_body' => false]]],
    [['checks' => ['not_screenshot' => false]]],
    [['checks' => ['not_logo' => false]]],
    [['checks' => ['whole_vehicle' => null]]],
    [['image_sha256' => 'different-image']],
    [['vehicle_sha256' => 'different-car']],
    [['revision' => 'unknown']],
    [['confidence' => 0.8]],
    [['verdict' => 'reject', 'reason' => 'logo', 'confidence' => 0.1]],
    [['verdict' => 'uncertain']],
]);

test('inconclusive lightweight evidence preserves an existing photo without redownloading', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $g = storedAutoPhoto(lightReviewFixture('ambiguous'));
    $before = $g->getRawOriginal();
    expect(fn () => app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, 'https://images.example.test/other.jpg'))->toThrow(VehicleImageNotFound::class);
    $plan = app(VehicleImageReview::class)->plan();
    expect($plan['entries'][$g->id]['action'])->toBe('review')->and($g->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

test('an unchanged URL does not whitelist a bad old image', function (): void {
    $g = storedAutoPhoto();
    $old = Storage::disk('public')->get($g->image);
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, $r['image_sha256'] === hash('sha256', $old) ? ['verdict' => 'reject', 'reason' => 'wrong_generation'] : []));
    Http::fake([$g->image_source_url => Http::response(test_image_binary('jpeg', 900, 500), 200, ['Content-Type' => 'image/jpeg'])]);
    $saved = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, $g->image_source_url);
    expect($saved->image)->not->toBe('old.webp')->and(app(VehicleImageQuality::class)->stored($saved)['state'])->toBe('accepted');
});

test('visual service failure remains unknown and does not label an existing photo wrong', function (): void {
    config(['vehicle_images.verifier_url' => 'https://vision.example.test/check']);
    Http::fake(['https://vision.example.test/check' => Http::response('', 503)]);
    $g = storedAutoPhoto();
    expect(app(VehicleImageQuality::class)->stored($g, true)['state'])->toBe('unverified');
    expect(app(VehicleImageReview::class)->plan(true)['entries'][$g->id]['action'])->toBe('review');
    Storage::disk('public')->assertExists('old.webp');
});

test('local review covers the entire image scope without catalogue writes or network calls', function (): void {
    foreach (range(1, 4) as $n) {
        photoVehicle(['image_source_url' => 'https://images.example.test/'.$n.'.jpg']);
    }
    $writes = [];
    DB::listen(function ($q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|alter|create|drop)/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });
    $plan = app(VehicleImageReview::class)->plan();
    expect($plan['entries'])->toHaveCount(4)->and($plan['counts']['fetch'])->toBe(4)->and($writes)->toBe([]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('approved repair clears a proven bad image when no valid photo exists and replay is inert', function (): void {
    $g = storedAutoPhoto();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->forProduct($product)->create(['price' => 1234, 'sku' => 'KEEP-IMAGE-REPAIR']);
    DB::table('product_fitments')->insert(['product_id' => $product->id, 'vehicle_generation_id' => $g->id, 'is_primary' => true, 'note' => 'keep']);
    $variantBefore = $variant->fresh()->getRawOriginal();
    $fitmentsBefore = DB::table('product_fitments')->get()->toJson();
    $productBefore = $product->fresh()->getRawOriginal();
    $vehicleBefore = $g->fresh()->getRawOriginal();
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, ['verdict' => 'reject', 'reason' => 'logo']));
    Http::fake([$g->image_source_url => Http::response('', 404)]);
    $review = app(VehicleImageReview::class);
    $plan = $review->plan(true);
    expect($plan['entries'][$g->id]['action'])->toBe('repair');
    expect(fn () => $review->apply($plan, 'not-approved'))->toThrow(RuntimeException::class);
    expect($g->fresh()->image)->toBe('old.webp');
    expect($review->apply($plan, $plan['seal']))->toBe([$g->id => 'cleared']);
    $requests = Http::recorded()->count();
    expect($review->apply($plan, $plan['seal']))->toBe([])->and(Http::recorded()->count())->toBe($requests)
        ->and($g->fresh()->image)->toBeNull()->and($product->fresh()->getRawOriginal())->toBe($productBefore)
        ->and(Arr::except($g->fresh()->getRawOriginal(), ['image', 'image_checksum', 'image_conversions', 'updated_at']))
        ->toBe(Arr::except($vehicleBefore, ['image', 'image_checksum', 'image_conversions', 'updated_at']))
        ->and(ImportRun::count())->toBe(0);
    expect($variant->fresh()->getRawOriginal())->toBe($variantBefore)->and(DB::table('product_fitments')->get()->toJson())->toBe($fitmentsBefore);
    Storage::disk('public')->assertExists('old.webp');
    Queue::assertNothingPushed();
});

test('manual replacement while a candidate is being checked wins and temporary images are removed', function (): void {
    $g = storedAutoPhoto();
    $old = Storage::disk('public')->get('old.webp');
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, $r['image_sha256'] === hash('sha256', $old) ? ['verdict' => 'reject', 'reason' => 'advertisement'] : []));
    Http::fake([$g->image_source_url => function () use ($g) {
        Storage::disk('public')->put('manual.webp', test_image_binary('webp', 800, 500));
        DB::table('vehicle_generations')->where('id', $g->id)->update(['image' => 'manual.webp', 'image_source_url' => null]);

        return Http::response(test_image_binary('png', 900, 500), 200, ['Content-Type' => 'image/png']);
    }]);
    expect(fn () => app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, $g->image_source_url))->toThrow(RuntimeException::class);
    expect($g->fresh()->image)->toBe('manual.webp')->and(Storage::disk('public')->allFiles())->toHaveCount(2);
});

test('manual images and permanent vehicle protection are never removed by an image repair plan', function (): void {
    $g = storedAutoPhoto();
    $p = Product::factory()->create();
    DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $g->id, 'is_primary' => true]);
    $run = ImportRun::factory()->create();
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$p->id => 'hold'], 2, str_repeat('a', 64)));
    $manual = photoVehicle();
    DB::table('vehicle_generations')->where('id', $manual->id)->update(['image' => 'old.webp', 'image_source_url' => null]);
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, ['verdict' => 'reject', 'reason' => 'wrong_vehicle']));
    $review = app(VehicleImageReview::class);
    $plan = $review->plan(true);
    expect($plan['entries'][$g->id]['preservation'])->toBe('permanent_protection')
        ->and($plan['entries'][$manual->id]['preservation'])->toBe('manual_image')
        ->and($review->apply($plan, $plan['seal']))->toBe([])
        ->and($g->fresh()->image)->toBe('old.webp')->and($manual->fresh()->image)->toBe('old.webp')
        ->and(DB::table('catalog_import_protections')->count())->toBe(1);
});

test('edited image facts and altered plan entries cannot authorize repair', function (string $change): void {
    $g = storedAutoPhoto();
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, ['verdict' => 'reject', 'reason' => 'logo']));
    $review = app(VehicleImageReview::class);
    $plan = $review->plan(true);
    if ($change === 'plan') {
        $plan['entries'][$g->id]['urls'] = ['https://other.test/new.jpg'];
    }
    if ($change === 'file') {
        Storage::disk('public')->put('old.webp', test_image_binary('webp', 800, 500));
    }
    if ($change === 'vehicle') {
        DB::table('vehicle_generations')->where('id', $g->id)->update(['years_label' => '2010-2015']);
    }
    if ($change === 'policy') {
        config(['vehicle_images.verifier_revision' => '2']);
    }
    expect(fn () => $review->apply($plan, $plan['seal']))->toThrow(RuntimeException::class);
    expect($g->fresh()->image)->toBe('old.webp');
})->with(['plan', 'file', 'vehicle', 'policy']);

test('a successful HTTP logo is rejected before any public file is written', function (): void {
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, ['verdict' => 'reject', 'reason' => 'logo']));
    Http::fake(['https://images.example.test/logo.jpg' => Http::response(test_image_binary('jpeg', 800, 500), 200, ['Content-Type' => 'image/jpeg'])]);
    $g = photoVehicle();
    expect(fn () => app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, 'https://images.example.test/logo.jpg'))->toThrow(VehicleImageNotFound::class);
    expect($g->fresh()->image)->toBeNull()->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('approved repair saves a verified replacement and keeps the old file for recovery', function (): void {
    $g = storedAutoPhoto();
    $old = Storage::disk('public')->get('old.webp');
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, $r['image_sha256'] === hash('sha256', $old) ? ['verdict' => 'reject', 'reason' => 'wrong_body'] : []));
    Http::fake([$g->image_source_url => Http::response(test_image_binary('png', 900, 500), 200, ['Content-Type' => 'image/png'])]);
    $review = app(VehicleImageReview::class);
    $plan = $review->plan(true);
    expect($review->apply($plan, $plan['seal']))->toBe([$g->id => 'saved'])
        ->and($g->fresh()->image)->not->toBe('old.webp')->and($review->apply($plan, $plan['seal']))->toBe([]);
    Storage::disk('public')->assertExists('old.webp');
});

test('new protection added after image review blocks the whole selected batch before image writes', function (): void {
    $g = storedAutoPhoto();
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, ['verdict' => 'reject', 'reason' => 'logo']));
    $review = app(VehicleImageReview::class);
    $plan = $review->plan(true);
    $p = Product::factory()->create();
    DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $g->id, 'is_primary' => true]);
    $run = ImportRun::factory()->create();
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$p->id => 'hold'], 2, str_repeat('a', 64)));
    expect(fn () => $review->apply($plan, $plan['seal']))->toThrow(RuntimeException::class);
    expect($g->fresh()->image)->toBe('old.webp');
});

test('image repair resumes in bounded batches and never repeats completed downloads', function (): void {
    foreach (range(1, 3) as $n) {
        storedAutoPhoto();
    }
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, ['verdict' => 'reject', 'reason' => 'advertisement']));
    Http::fake(['https://images.example.test/old.jpg' => Http::response('', 404)]);
    $review = app(VehicleImageReview::class);
    $plan = $review->plan(true);
    foreach (range(1, 3) as $n) {
        expect($review->apply($plan, $plan['seal'], 1))->toHaveCount(1)
            ->and(VehicleGeneration::whereNull('image')->count())->toBe($n);
    }
    $requests = Http::recorded()->count();
    expect($review->apply($plan, $plan['seal']))->toBe([])->and(Http::recorded()->count())->toBe($requests);
});

test('review command is read-only and reprocess command refuses to run without separate confirmation', function (): void {
    $g = storedAutoPhoto();
    $before = $g->getRawOriginal();
    $this->artisan('vehicle-images:review', ['--output' => 'images-plan.json'])->assertSuccessful();
    $this->artisan('vehicle-images:reprocess', ['plan' => Storage::disk('local')->path('images-plan.json')])->assertFailed();
    expect($g->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('image-only source workbook contributes URLs without product columns or a FULL', function (): void {
    $g = photoVehicle();
    $g->model->make->forceFill(['title' => 'Example', 'norm_key' => 'example'])->save();
    $g->model->forceFill(['title' => 'Car', 'norm_key' => 'car'])->save();
    $writer = new Writer;
    $path = Storage::disk('local')->path('photos.xlsx');
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(array_fill(0, 6, null)));
    $writer->addRow(Row::fromValues(['Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Фото']));
    $writer->addRow(Row::fromValues(['Example', 'Car', '1', '2000-2005', 'Седан 4 дв.', 'https://images.example.test/photo.jpg']));
    $writer->close();
    $plan = app(VehicleImageReview::class)->plan(xlsx: $path);
    expect($plan['entries'][$g->id]['urls'])->toBe(['https://images.example.test/photo.jpg'])
        ->and($plan['unresolved_source_rows'])->toBe(0)->and(ImportRun::count())->toBe(0);
    file_put_contents($path, 'changed workbook');
    expect(fn () => app(VehicleImageReview::class)->apply($plan, $plan['seal']))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('lack of a verified vehicle photo never becomes a product row error', function (): void {
    config(['vehicle_images.verifier_url' => null]);
    $g = photoVehicle();
    $run = ImportRun::factory()->create(['status' => 'processing_images', 'queued_images' => 1]);
    app()->call([new DownloadVehicleGenerationImageJob($g->id, 'https://images.example.test/logo.jpg', $run->id), 'handle']);
    expect($run->fresh()->failed_images)->toBe(1)->and($run->fresh()->errors_count)->toBe(0)->and($g->fresh()->image)->toBeNull();
    Http::assertNothingSent();
});

test('a shared rejected logo is checked once but a good photo is never approved for another vehicle by hash alone', function (): void {
    $acceptedId = null;
    fake_vehicle_photo_verifier(function ($r) use (&$acceptedId) {
        return test_vehicle_verdict($r, $r['vehicle']['id'] === $acceptedId ? [] : [
            'verdict' => 'reject', 'reason' => $r['revision'] === 'fresh' ? 'wrong_generation' : 'logo',
        ]);
    });
    $bytes = test_image_binary('png', 800, 500);
    $quality = app(VehicleImageQuality::class);
    expect($quality->assess(photoVehicle(), $bytes)['state'])->toBe('rejected');
    $count = Http::recorded()->count();
    expect($quality->assess(photoVehicle(), $bytes)['state'])->toBe('rejected')->and(Http::recorded()->count())->toBe($count);
    // A new verifier policy makes the next two evaluations independent of that rejected content cache.
    config(['vehicle_images.verifier_revision' => 'fresh']);
    $a = photoVehicle();
    $b = photoVehicle();
    $acceptedId = $a->id;
    expect($quality->assess($a, $bytes)['state'])->toBe('accepted')->and($quality->assess($b, $bytes)['state'])->toBe('rejected');
});

test('the generic image downloader cannot bypass vehicle relevance verification', function (): void {
    expect(fn () => app(ImageDownloadService::class)->download('https://images.example.test/logo.jpg', 'vehicle_image', 'uploads/vehicles/generations/1'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('an unreadable local file remains unknown rather than becoming a deletion candidate', function (): void {
    $g = storedAutoPhoto();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturnTrue();
    $disk->shouldReceive('size')->andReturn(1000);
    $disk->shouldReceive('get')->andThrow(new RuntimeException('unreadable'));
    Storage::partialMock()->shouldReceive('disk')->with('public')->andReturn($disk);
    expect(app(VehicleImageQuality::class)->stored($g, true)['state'])->toBe('unverified');
    Http::assertNothingSent();
});

test('oversized local images are rejected without sending their bytes to a visual service', function (): void {
    $g = storedAutoPhoto();
    config(['media.max_source_size' => 10]);
    expect(app(VehicleImageQuality::class)->stored($g, true)['state'])->toBe('rejected');
    Http::assertNothingSent();
});

test('an expired image approval cannot change a photograph', function (): void {
    $g = storedAutoPhoto();
    fake_vehicle_photo_verifier(fn ($r) => test_vehicle_verdict($r, ['verdict' => 'reject', 'reason' => 'logo']));
    $review = app(VehicleImageReview::class);
    $plan = $review->plan(true);
    $this->travel(25)->hours();
    expect(fn () => $review->apply($plan, $plan['seal']))->toThrow(RuntimeException::class);
    expect($g->fresh()->image)->toBe('old.webp');
});

test('manual upload after an automatic image removes old source provenance and cannot be overwritten on retry', function (): void {
    $g = storedAutoPhoto();
    Storage::disk('public')->put('uploads/vehicles/generations/manual/edit.jpg', test_image_binary('jpeg', 900, 500));
    $g->forceFill(['image' => 'uploads/vehicles/generations/manual/edit.jpg'])->save();
    $g->refresh();
    expect($g->image_source_url)->toBeNull();
    $path = $g->image;
    $saved = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($g, 'https://images.example.test/old.jpg');
    expect($saved->image)->toBe($path)->and(app(VehicleImageReview::class)->plan()['entries'][$g->id]['preservation'])->toBe('manual_image');
    Http::assertNothingSent();
});
