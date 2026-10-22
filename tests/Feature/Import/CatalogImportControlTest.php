<?php

use App\Enums\ImportRunStatus;
use App\Filament\Pages\CatalogImportPage;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Jobs\DownloadProductImageJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Import\ImportImageDownloader;
use App\Services\Import\ImportProductFactory;
use App\Services\Import\ImportProgressService;
use App\Services\Import\ImportRowProcessor;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\SpreadsheetReader;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

/** Prepare a real, valid snapshot: old raw CSV checkpoints are not accepted by the importer. */
function controlPreparedSnapshot(int $rows, int $chunkSize): ImportRun
{
    Storage::fake('local');
    Queue::fake();
    test()->seed([ProductCatalogSeeder::class, PartTypeSeeder::class, ProductOptionSeeder::class]);
    app(CatalogConfigurations::class)->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    $path = Storage::disk('local')->path('imports/catalog/progress.xlsx');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues([null, null, null, null, null, null, 'Порог']));
    $writer->addRow(Row::fromValues([
        'Фото', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Порог',
    ]));
    for ($i = 1; $i <= $rows; $i++) {
        $writer->addRow(Row::fromValues([
            null, 'Toyota', 'Camry '.$i, 'XV70', '2017–2023', 'Седан', 1,
        ]));
    }
    $writer->close();
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', 'progress.xlsx', 'imports/catalog/progress.xlsx', chunkSize: $chunkSize);
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    expect($run->fresh()->status)->toBe(ImportRunStatus::RunningRows);

    return $run->refresh();
}

test('ImportProgress combines row and image work and reserves 100 percent for done', function () {
    $service = app(ImportProgressService::class);

    $withImages = ImportRun::factory()->create([
        'status' => ImportRunStatus::ProcessingImages,
        'total_rows' => 10,
        'processed_rows' => 10,
        'queued_images' => 2,
        'processed_images' => 1,
    ]);

    $progress = $service->forRun($withImages);

    expect($progress->rowsPercent)->toBe(100)
        ->and($progress->imagesPercent)->toBe(50)
        ->and($progress->overallPercent)->toBe(91)
        ->and($progress->overallPercent)->toBeLessThan(100);

    $withoutImages = ImportRun::factory()->create([
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 10,
        'processed_rows' => 4,
        'queued_images' => 0,
    ]);

    expect($service->forRun($withoutImages)->overallPercent)->toBe(40)
        ->and($service->forRun($withoutImages)->imagesLabel)->toBe('Изображения не найдены или не требуются');

    $withImages->forceFill(['status' => ImportRunStatus::Done])->save();

    expect($service->forRun($withImages->fresh())->overallPercent)->toBe(100);
});

test('ImportProgress reports done with warnings in Russian', function () {
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::Done,
        'warnings_count' => 1,
    ]);

    expect(app(ImportProgressService::class)->statusLabel($run))->toBe('Завершён с предупреждениями');
});

test('ImportStatus pause parks image job durably without warnings or failed counters', function () {
    Storage::fake('public');
    Http::fake();

    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::ProcessingImages,
        'queued_images' => 1,
    ]);
    app(ImportStatusService::class)->pause($run);

    $product = Product::factory()->create();
    $job = (new DownloadProductImageJob($product->getKey(), 'https://example.test/image.jpg', $run->getKey()))
        ->withFakeQueueInteractions();

    $job->handle(
        app(ImportImageDownloader::class),
        app(ImportLogger::class),
        app(ImportStatusService::class),
    );

    $job->assertDeleted();
    Http::assertNothingSent();

    expect($run->fresh()->status)->toBe(ImportRunStatus::Paused)
        ->and($run->fresh()->failed_images)->toBe(0)
        ->and($run->fresh()->warnings_count)->toBe(0);
});

test('ImportStatus canceled image job does not process counters or finish import', function () {
    Storage::fake('public');
    Http::fake();

    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::Canceled,
        'queued_images' => 1,
    ]);
    $product = Product::factory()->create();

    (new DownloadProductImageJob($product->getKey(), 'https://example.test/image.jpg', $run->getKey()))->handle(
        app(ImportImageDownloader::class),
        app(ImportLogger::class),
        app(ImportStatusService::class),
    );

    Http::assertNothingSent();
    expect($run->fresh()->status)->toBe(ImportRunStatus::Canceled)
        ->and($run->fresh()->processed_images)->toBe(0)
        ->and($run->fresh()->failed_images)->toBe(0);
});

test('ImportStatus resume is idempotent and preserves row and image counters', function () {
    Queue::fake();

    $this->actingAs(User::factory()->admin()->create());
    $page = app(CatalogImportPage::class);
    $rowsRun = ImportRun::factory()->create([
        'status' => ImportRunStatus::Paused,
        'total_rows' => 10,
        'processed_rows' => 4,
        'current_row' => 4,
        'initialized_at' => now(),
    ]);

    $page->resume($rowsRun->getKey());
    $page->resume($rowsRun->getKey());

    Queue::assertPushed(CatalogImportChunkJob::class, 1);
    expect($rowsRun->fresh()->processed_rows)->toBe(4)
        ->and($rowsRun->fresh()->current_row)->toBe(4);

    $imagesRun = ImportRun::factory()->create([
        'status' => ImportRunStatus::Paused,
        'total_rows' => 10,
        'processed_rows' => 10,
        'current_row' => 10,
        'queued_images' => 2,
        'processed_images' => 1,
    ]);

    $page->resume($imagesRun->getKey());
    $page->resume($imagesRun->getKey());

    Queue::assertPushed(CatalogImportChunkJob::class, 1);
    expect($imagesRun->fresh()->status)->toBe(ImportRunStatus::ProcessingImages)
        ->and($imagesRun->fresh()->processed_images)->toBe(1)
        ->and($imagesRun->fresh()->queued_images)->toBe(2);
});

test('ImportStatus repeated pause and cancel write one log per transition', function () {
    $service = app(ImportStatusService::class);
    $run = ImportRun::factory()->create(['status' => ImportRunStatus::RunningRows]);

    $service->pause($run);
    $service->pause($run);
    $service->cancel($run);
    $service->cancel($run);

    expect(ImportLog::query()->where('import_run_id', $run->getKey())->where('message', 'Импорт поставлен на паузу')->count())->toBe(1)
        ->and(ImportLog::query()->where('import_run_id', $run->getKey())->where('message', 'Импорт отменён пользователем')->count())->toBe(1)
        ->and($run->fresh()->status)->toBe(ImportRunStatus::Canceled);
});

test('ImportStatus paused and canceled row chunks do not process rows or archive products', function (ImportRunStatus $status) {
    Queue::fake();
    Storage::fake('local');
    Storage::disk('local')->put('imports/catalog/control.csv', "group,title\nheader,title\ndata,row\n");

    $run = ImportRun::factory()->create([
        'status' => $status,
        'stored_path' => 'imports/catalog/control.csv',
        'total_rows' => 1,
        'chunk_size' => 100,
        'detail_columns' => [],
    ]);
    $rowProcessor = Mockery::mock(ImportRowProcessor::class);
    $rowProcessor->shouldNotReceive('process');
    $products = Mockery::mock(ImportProductFactory::class);
    $products->shouldNotReceive('archiveMissingProducts');

    (new CatalogImportChunkJob($run->getKey()))->handle(
        app(SpreadsheetReader::class),
        app(ImportStatusService::class),
        app(ImportLogger::class),
        $rowProcessor,
        $products,
    );

    expect($run->fresh()->processed_rows)->toBe(0)
        ->and($run->fresh()->current_row)->toBe(0)
        ->and($run->fresh()->status)->toBe($status);
})->with([ImportRunStatus::Paused, ImportRunStatus::Canceled]);

test('ImportProgress row job stores progress and heartbeat inside a prepared XLSX chunk', function () {
    $run = controlPreparedSnapshot(100, 25);
    $calls = 0;
    $progressSeenInsideChunk = null;
    $rowProcessor = Mockery::mock(ImportRowProcessor::class);
    $rowProcessor->shouldReceive('process')->times(25)->andReturnUsing(function () use (&$calls, &$progressSeenInsideChunk, $run): void {
        $calls++;
        if ($calls === 21) {
            $progressSeenInsideChunk = $run->fresh()->processed_rows;
        }
    });

    (new CatalogImportChunkJob($run->getKey()))->handle(
        app(SpreadsheetReader::class),
        app(ImportStatusService::class),
        app(ImportLogger::class),
        $rowProcessor,
        app(ImportProductFactory::class),
    );

    expect($progressSeenInsideChunk)->toBe(20)
        ->and($run->fresh()->processed_rows)->toBe(25)
        ->and($run->fresh()->current_row)->toBe(25)
        ->and($run->fresh()->heartbeat_at)->not->toBeNull();
});

test('row errors finish prepared XLSX import as failed without a synthetic extra error', function () {
    $run = controlPreparedSnapshot(1, 1);
    $rowProcessor = Mockery::mock(ImportRowProcessor::class);
    $rowProcessor->shouldReceive('process')->once()->andThrow(new LogicException('row boom'));
    $products = Mockery::mock(ImportProductFactory::class);
    $products->shouldNotReceive('archiveMissingProducts');

    (new CatalogImportChunkJob($run->getKey()))->handle(
        app(SpreadsheetReader::class),
        app(ImportStatusService::class),
        app(ImportLogger::class),
        $rowProcessor,
        $products,
    );

    $run->refresh();
    expect($run->status)->toBe(ImportRunStatus::Failed)
        ->and($run->processed_rows)->toBe(1)
        ->and($run->current_row)->toBe(1)
        ->and($run->errors_count)->toBe(1)
        ->and($run->archive_skipped)->toBeTrue()
        ->and($run->archive_skip_reason)->toBe('row_errors')
        ->and($run->last_error)->toContain('row boom')
        ->and(ImportLog::query()->where('import_run_id', $run->getKey())->where('level', 'error')->count())->toBe(1)
        ->and(app(ImportProgressService::class)->statusLabel($run))->toBe('Ошибка');
});

test('ImportProgress distinguishes legacy done-with-errors from warnings', function () {
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::Done,
        'errors_count' => 1,
    ]);

    expect(app(ImportProgressService::class)->statusLabel($run))->toBe('Завершён с ошибками');
});
