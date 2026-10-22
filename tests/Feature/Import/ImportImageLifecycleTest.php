<?php

use App\Enums\ImportRunStatus;
use App\Jobs\DownloadProductImageJob;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Import\ImportImageDownloader;
use App\Services\Import\ImportImageRecovery;
use App\Services\Import\ImportImageTasks;
use App\Services\ImportStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (getenv('IMPORT_IMAGE_MYSQL_TEST') === '1') {
        expect(DB::getDriverName())->toBe('mysql')->and(DB::connection()->getDatabaseName())->toBe('dvashop_guard_test')
            ->and(DB::connection()->getConfig('host'))->toBe('dvashop_safe_partial_mysql_fix');
    }
    config(['queue.default' => 'database']);
    Storage::fake('local');
    Storage::fake('public');
    Http::preventStrayRequests();
});

function lifecycleRun(): ImportRun
{
    return ImportRun::factory()->create(['status' => ImportRunStatus::ProcessingImages, 'total_rows' => 1, 'processed_rows' => 1,
        'queued_images' => 0, 'processed_images' => 0, 'failed_images' => 0]);
}

function lifecycleTask(?ImportRun $run = null): array
{
    $run ??= lifecycleRun();
    $g = VehicleGeneration::factory()->create();
    $url = 'https://images.example.test/'.$g->id.'.jpg';
    app(ImportImageTasks::class)->enqueue($run, 'vehicle', $g->id, $url);

    return [$run->fresh(), $g, new DownloadVehicleGenerationImageJob($g->id, $url, $run->id)];
}

function lifecycleAgeTask(ImportRun $run): void
{
    $tasks = app(ImportImageTasks::class);
    foreach ($run->logs()->whereNotNull('context->image_task')->get() as $log) {
        $tasks->updateTask($log, $log->context['image_task'], ['last_activity' => now()->subHour()->timestamp, 'lease_until' => null]);
    }
}

test('the reproduced pause race commits its receipt and counter exactly once and resumes to done', function (): void {
    [$run, $g, $job] = lifecycleTask();
    $tasks = app(ImportImageTasks::class);
    $claim = $tasks->start($job, 'vehicle');
    app(ImportStatusService::class)->pause($run);
    $tasks->complete($job, 'vehicle', true, ['reused' => true], $claim['token']);
    expect($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Paused);
    $tasks->complete($job, 'vehicle', true, [], $claim['token']);
    expect($run->fresh()->processed_images)->toBe(1);
    app(ImportStatusService::class)->resume($run);
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done)->and($run->fresh()->failed_images)->toBe(0);
    Http::assertNothingSent();
});

test('expected scope counter and database delivery commit or roll back together and enqueue is idempotent', function (): void {
    $run = lifecycleRun();
    $g = VehicleGeneration::factory()->create();
    $tasks = app(ImportImageTasks::class);
    DB::beginTransaction();
    $tasks->enqueue($run, 'vehicle', $g->id, 'https://example.test/a.jpg');
    expect(DB::table('jobs')->count())->toBe(1)->and($run->fresh()->queued_images)->toBe(1);
    DB::rollBack();
    expect(DB::table('jobs')->count())->toBe(0)->and($run->fresh()->queued_images)->toBe(0)->and($run->logs()->count())->toBe(0);
    expect($tasks->enqueue($run, 'vehicle', $g->id, 'https://example.test/a.jpg'))->toBeTrue()
        ->and($tasks->enqueue($run, 'vehicle', $g->id, 'https://example.test/a.jpg'))->toBeFalse()
        ->and($run->fresh()->queued_images)->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

test('a legacy receipt written during pause reconciles counters without replaying any image or row', function (): void {
    $run = lifecycleRun();
    $key = '936:'.hash('sha256', 'https://example.test/image.jpg');
    $run->update(['queued_vehicle_image_keys' => [$key], 'queued_images' => 1]);
    ImportLog::create(['import_run_id' => $run->id, 'level' => 'info', 'message' => 'Изображение поколения авто сохранено',
        'context' => ['vehicle_image_result_key' => $key.':initial', 'result' => 'saved', 'retry_of_log_id' => null]]);
    $recovery = app(ImportImageRecovery::class);
    $preview = $recovery->inspect($run);
    expect($preview['conflicts'])->toBe([])->and($preview['counts'])->toBe(['finished' => 1])->and($preview['after']['processed'])->toBe(1);
    expect($run->fresh()->processed_images)->toBe(0);
    $recovery->recover($run, $preview['confirmation']);
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done)->and($run->fresh()->processed_images)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
});

test('a job lost without a result is restored once after the visibility grace without repeating FULL', function (): void {
    [$run] = lifecycleTask();
    DB::table('jobs')->delete();
    $recovery = app(ImportImageRecovery::class);
    expect($recovery->inspect($run)['counts'])->toBe(['awaiting_lease_or_queue_visibility' => 1]);
    lifecycleAgeTask($run);
    $preview = $recovery->inspect($run);
    expect($preview['counts'])->toBe(['lost' => 1]);
    $recovery->recover($run, $preview['confirmation']);
    expect(DB::table('jobs')->count())->toBe(1)->and($run->fresh()->queued_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::ProcessingImages);
    $recovery->recover($run);
    expect(DB::table('jobs')->count())->toBe(1)->and(ImportRun::count())->toBe(1);
});

test('queued delayed reserved and running image jobs cannot be declared lost or completed', function (): void {
    [$run, $g, $job] = lifecycleTask();
    lifecycleAgeTask($run);
    $recovery = app(ImportImageRecovery::class);
    expect($recovery->inspect($run)['counts'])->toBe(['queued' => 1]);
    $queued = Queue::connection('database')->pop('imports-images');
    expect($queued)->not->toBeNull();
    expect($recovery->inspect($run)['counts'])->toBe(['running' => 1]);
    $claim = app(ImportImageTasks::class)->start($job, 'vehicle');
    expect($claim['state'])->toBe('run')->and($recovery->inspect($run)['counts'])->toBe(['running' => 1]);
    $recovery->recover($run);
    expect($run->fresh()->status)->toBe(ImportRunStatus::ProcessingImages)->and($run->fresh()->processed_images)->toBe(0)->and(DB::table('jobs')->count())->toBe(1);
    app(ImportImageTasks::class)->complete($job, 'vehicle', true, [], $claim['token']);
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done);
    $queued->delete();
});

test('duplicate real queue delivery records one result and a late delivery after done performs no download', function (): void {
    [$run, $g, $job] = lifecycleTask();
    $mock = $this->mock(ImportImageDownloader::class);
    $mock->shouldReceive('canReuseVehicleImage')->once()->andReturn(true);
    $mock->shouldNotReceive('downloadVehicleGenerationImage');
    app(ImportImageTasks::class)->dispatch(clone $job);
    Queue::connection('database')->pop('imports-images')->fire();
    Queue::connection('database')->pop('imports-images')->fire();
    expect($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and($run->logs()->whereNotNull('context->vehicle_image_result_key')->count())->toBe(1);
    app()->call([$job, 'handle']);
    Http::assertNothingSent();
});

test('historical serialized jobs without default alternative and retry fields still execute safely', function (): void {
    [$run, $g, $job] = lifecycleTask();
    $payload = $job->__serialize();
    unset($payload['alternativeUrls'], $payload['retryOfLogId'], $payload['imageTaskEpoch'], $payload['imageTaskToken']);
    $legacy = (new ReflectionClass(DownloadVehicleGenerationImageJob::class))->newInstanceWithoutConstructor();
    $legacy->__unserialize($payload);
    $mock = $this->mock(ImportImageDownloader::class);
    $mock->shouldReceive('canReuseVehicleImage')->once()->andReturn(false);
    $mock->shouldReceive('downloadVehicleGenerationImage')->once()->andReturn($g);
    app()->call([$legacy, 'handle']);
    expect($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Done);
});

test('product image results are idempotent as well as generation image results', function (): void {
    $run = lifecycleRun();
    $p = Product::factory()->create();
    $tasks = app(ImportImageTasks::class);
    $tasks->enqueue($run, 'product', $p->id, 'https://example.test/product.jpg');
    $job = new DownloadProductImageJob($p->id, 'https://example.test/product.jpg', $run->id);
    $claim = $tasks->start($job, 'product');
    $tasks->complete($job, 'product', true, [], $claim['token']);
    $tasks->complete($job, 'product', false, [], $claim['token']);
    expect($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->failed_images)->toBe(0)
        ->and($run->logs()->whereNotNull('context->product_image_result_key')->count())->toBe(1);
});

test('three actual failed download attempts get a final reported error and complete the import', function (): void {
    [$run] = lifecycleTask();
    $mock = $this->mock(ImportImageDownloader::class);
    $mock->shouldReceive('canReuseVehicleImage')->times(3)->andReturn(false);
    $mock->shouldReceive('downloadVehicleGenerationImage')->times(3)->andThrow(new RuntimeException('HTTP 503'));
    foreach (range(1, 3) as $attempt) {
        Queue::connection('database')->pop('imports-images')->fire();
        if ($attempt < 3) {
            expect($run->fresh()->failed_images)->toBe(0)->and($run->fresh()->status)->toBe(ImportRunStatus::ProcessingImages);
            $this->travel(31)->seconds();
        }
    }
    expect($run->fresh()->failed_images)->toBe(1)->and($run->fresh()->errors_count)->toBe(0)->and($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and($run->logs()->where('level', 'warning')->whereNotNull('context->failed_sources')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a paused worker delivery parks durably and resume requeues only that image', function (): void {
    [$run] = lifecycleTask();
    app(ImportStatusService::class)->pause($run);
    Queue::connection('database')->pop('imports-images')->fire();
    expect(DB::table('jobs')->count())->toBe(0)->and($run->fresh()->failed_images)->toBe(0);
    app(ImportStatusService::class)->resume($run);
    expect(DB::table('jobs')->count())->toBe(1)->and($run->fresh()->queued_images)->toBe(1);
    $this->mock(ImportImageDownloader::class)->shouldReceive('canReuseVehicleImage')->once()->andReturn(true);
    Queue::connection('database')->pop('imports-images')->fire();
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done);
});

test('worker death is recovered after reservation and lease expire and stale execution cannot overwrite its successor', function (): void {
    [$run, $g, $job] = lifecycleTask();
    $old = app(ImportImageTasks::class)->start($job, 'vehicle');
    DB::table('jobs')->delete(); // Reproduces a vanished delivery while its execution lease still exists.
    expect(app(ImportImageRecovery::class)->inspect($run)['counts'])->toBe(['running' => 1]);
    $this->travel(700)->seconds();
    app(ImportImageRecovery::class)->recover($run);
    $replacement = clone $job;
    $replacement->imageTaskEpoch = 1;
    $new = app(ImportImageTasks::class)->start($replacement, 'vehicle');
    app(ImportImageTasks::class)->complete($job, 'vehicle', false, [], $old['token']);
    expect($run->fresh()->failed_images)->toBe(0);
    app(ImportImageTasks::class)->complete($replacement, 'vehicle', true, [], $new['token']);
    expect($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Done);
});

test('exhausted lost task attempts finish with a warning rather than rescheduling forever', function (): void {
    [$run, $g, $job] = lifecycleTask();
    DB::table('jobs')->delete();
    lifecycleAgeTask($run);
    $log = $run->logs()->whereNotNull('context->image_task')->first();
    app(ImportImageTasks::class)->updateTask($log, $log->context['image_task'], ['attempts' => 3]);
    app(ImportImageRecovery::class)->recover($run);
    expect($run->fresh()->failed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Done)->and(DB::table('jobs')->count())->toBe(0);
});

test('a restarted worker receives the expired reservation and safely resumes the same image task', function (): void {
    [$run, $g, $job] = lifecycleTask();
    $reserved = Queue::connection('database')->pop('imports-images');
    $job->setJob($reserved);
    app(ImportImageTasks::class)->start($job, 'vehicle');
    $this->travel(661)->seconds(); // No deletion, no failed record: the worker died holding its reservation.
    $this->mock(ImportImageDownloader::class)->shouldReceive('canReuseVehicleImage')->once()->andReturn(true);
    Queue::connection('database')->pop('imports-images')->fire();
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done)->and($run->fresh()->processed_images)->toBe(1)
        ->and($run->logs()->whereNotNull('context->vehicle_image_result_key')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a late failure callback from a superseded delivery cannot finish its queued replacement', function (): void {
    [$run, $g, $job] = lifecycleTask();
    DB::table('jobs')->delete();
    lifecycleAgeTask($run);
    app(ImportImageRecovery::class)->recover($run);
    $job->failed(new RuntimeException('late failure of the old delivery'));
    expect($run->fresh()->failed_images)->toBe(0)->and($run->fresh()->status)->toBe(ImportRunStatus::ProcessingImages)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('mixed final successes and errors complete without touching any catalogue data images or protections', function (): void {
    [$run, $g, $first] = lifecycleTask();
    [, , $second] = lifecycleTask($run);
    $p = Product::factory()->create();
    ProductVariant::factory()->forProduct($p)->create(['price' => 4321, 'sku' => 'KEEP-LIFECYCLE']);
    DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $g->id, 'is_primary' => true]);
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$p->id => 'lifecycle fixture'], 2, str_repeat('a', 64)));
    Storage::disk('public')->put('keep.webp', 'unchanged pixels');
    $tables = ['products', 'product_variants', 'product_fitments', 'vehicle_generations', 'catalog_import_protections'];
    $before = array_map(fn ($t) => DB::table($t)->orderBy($t === 'catalog_import_protections' ? 'product_id' : 'id')->get()->toJson(), $tables);
    foreach ([$first, $second] as $index => $job) {
        $claim = app(ImportImageTasks::class)->start($job, 'vehicle');
        app(ImportImageTasks::class)->complete($job, 'vehicle', $index === 0, ['error' => 'fixture'], $claim['token']);
    }
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done)->and($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->failed_images)->toBe(1)
        ->and(array_map(fn ($t) => DB::table($t)->orderBy($t === 'catalog_import_protections' ? 'product_id' : 'id')->get()->toJson(), $tables))->toBe($before)
        ->and(Storage::disk('public')->get('keep.webp'))->toBe('unchanged pixels')->and(ImportRun::count())->toBe(1);
});

test('an uncommitted result transaction rolls back both receipt and counter and can be redelivered', function (): void {
    [$run, $g, $job] = lifecycleTask();
    $claim = app(ImportImageTasks::class)->start($job, 'vehicle');
    DB::beginTransaction();
    app(ImportImageTasks::class)->complete($job, 'vehicle', true, [], $claim['token']);
    DB::rollBack();
    expect($run->fresh()->processed_images)->toBe(0)->and($run->logs()->whereNotNull('context->vehicle_image_result_key')->count())->toBe(0);
    app(ImportImageTasks::class)->complete($job, 'vehicle', true, [], $claim['token']);
    expect($run->fresh()->processed_images)->toBe(1);
});

test('recovery refuses stale confirmations ambiguous scope and unknown queue backends', function (): void {
    [$run] = lifecycleTask();
    $recovery = app(ImportImageRecovery::class);
    $plan = $recovery->inspect($run);
    $run->update(['processed_images' => 1]);
    expect(fn () => $recovery->recover($run, $plan['confirmation']))->toThrow(RuntimeException::class);
    $run->update(['queued_images' => 2]);
    expect($recovery->inspect($run)['conflicts'])->toContain('expected_scope_does_not_match_queued_images');
    expect(fn () => $recovery->recover($run))->toThrow(RuntimeException::class);
    config(['queue.default' => 'redis']);
    expect(fn () => $recovery->inspect($run))->toThrow(RuntimeException::class);
});

test('a timeout failure callback is idempotent and late jobs never reopen done imports', function (): void {
    [$run, $g, $job] = lifecycleTask();
    $job->failed(new RuntimeException('timeout'));
    $job->failed(new RuntimeException('timeout'));
    app()->call([$job, 'handle']);
    expect($run->fresh()->failed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and($run->logs()->whereNotNull('context->vehicle_image_result_key')->count())->toBe(1);
    Http::assertNothingSent();
});

test('the recovery CLI is read-only by default and requires an exact confirmation to apply', function (): void {
    [$run] = lifecycleTask();
    $before = $run->getRawOriginal();
    $logs = $run->logs()->count();
    $this->artisan('catalog-import:recover-images', ['run' => $run->id])->assertSuccessful();
    $this->artisan('catalog-import:recover-images', ['run' => $run->id, '--apply' => true])->assertFailed();
    expect($run->fresh()->getRawOriginal())->toBe($before)->and($run->logs()->count())->toBe($logs);
});

test('recovery adopts a result from an old worker without leaving the new expected journal pending', function (): void {
    [$run, $g, $job] = lifecycleTask();
    DB::table('jobs')->delete();
    ImportLog::create(['import_run_id' => $run->id, 'level' => 'info', 'message' => 'Legacy result', 'context' => [
        'vehicle_image_result_key' => $g->id.':'.hash('sha256', $job->url).':initial', 'result' => 'saved']]);
    $preview = app(ImportImageRecovery::class)->inspect($run);
    $this->artisan('catalog-import:recover-images', ['run' => $run->id, '--apply' => true, '--confirm' => $preview['confirmation']])->assertSuccessful();
    expect($run->fresh()->status)->toBe(ImportRunStatus::Done)->and($run->fresh()->processed_images)->toBe(1)
        ->and($run->fresh()->hasPendingImages())->toBeFalse();
    Http::assertNothingSent();
});
