<?php

use App\Enums\ImportLogLevel;
use App\Enums\ImportRunStatus;
use App\Enums\UserRole;
use App\Filament\Pages\CatalogImportPage;
use App\Jobs\DownloadProductImageJob;
use Livewire\Livewire;
use Illuminate\Support\Facades\Queue;
use App\Jobs\CatalogImportStartJob;
use App\Models\Product;
use App\Models\User;
use App\Services\ImportRunReportExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Http;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function importCsvContent(): string
{
    return "make,model,generation\nToyota,Camry,XV70\nLada,Vesta,NG\n";
}

test('file upload creates import run', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->createWithContent('catalog.csv', importCsvContent());
    $run = app(ImportStatusService::class)->createFromUpload($file);

    expect($run)->toBeInstanceOf(ImportRun::class)
        ->and($run->status)->toBe(ImportRunStatus::Ready)
        ->and($run->type)->toBe('catalog')
        ->and($run->original_name)->toBe('catalog.csv')
        ->and($run->file_hash)->toHaveLength(64);

    Storage::disk('local')->assertExists($run->stored_path);
});

test('file upload metadata is read from stored import file instead of temporary upload', function () {
    Storage::fake('local');

    $path = storage_path('framework/testing/livewire-metadata-catalog.csv');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, importCsvContent());

    $file = new class($path, 'catalog.csv', 'text/csv', null, true) extends UploadedFile {
        public function getSize(): int|false
        {
            throw new RuntimeException('Temporary upload metadata must not be read after storing.');
        }
    };

    $run = app(ImportStatusService::class)->createFromUpload($file);

    expect($run->original_name)->toBe('catalog.csv')
        ->and($run->file_size)->toBe(strlen(importCsvContent()))
        ->and($run->file_hash)->toHaveLength(64);

    Storage::disk('local')->assertExists($run->stored_path);
});

test('start changes import status', function () {
    $run = ImportRun::factory()->create(['status' => ImportRunStatus::Ready]);

    app(ImportStatusService::class)->start($run);

    expect($run->fresh()->status)->toBe(ImportRunStatus::RunningRows)
        ->and($run->fresh()->started_at)->not->toBeNull()
        ->and($run->fresh()->heartbeat_at)->not->toBeNull();
});

test('pause changes import status', function () {
    $run = ImportRun::factory()->create(['status' => ImportRunStatus::Running]);

    app(ImportStatusService::class)->pause($run);

    expect($run->fresh()->status)->toBe(ImportRunStatus::Paused);
});

test('spreadsheet reader counts csv rows and reads chunks', function () {
    $path = storage_path('framework/testing/catalog-import.csv');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, importCsvContent());

    $reader = app(SpreadsheetReader::class);

    expect($reader->readHeader($path))->toBe(['make', 'model', 'generation'])
        ->and($reader->countRows($path))->toBe(2)
        ->and($reader->readChunk($path, 0, 1))->toBe([
            ['Toyota', 'Camry', 'XV70'],
        ])
        ->and($reader->readChunk($path, 1, 10))->toBe([
            ['Lada', 'Vesta', 'NG'],
        ]);
});



test('spreadsheet reader rejects txt files', function () {
    $path = storage_path('framework/testing/catalog-import.txt');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, importCsvContent());

    $reader = app(SpreadsheetReader::class);

    expect($reader->supports($path))->toBeFalse();
    expect(fn () => $reader->countRows($path))->toThrow(\InvalidArgumentException::class);
});

test('import logger writes logs', function () {
    $run = ImportRun::factory()->create();

    app(ImportLogger::class)->warning($run, 'Проверочное предупреждение', ['row' => 2]);

    $log = ImportLog::query()->firstOrFail();

    expect($log->import_run_id)->toBe($run->getKey())
        ->and($log->level)->toBe(ImportLogLevel::Warning)
        ->and($log->message)->toBe('Проверочное предупреждение')
        ->and($log->context)->toBe(['row' => 2]);
});


test('resume and cancel change import status', function () {
    $run = ImportRun::factory()->create(['status' => ImportRunStatus::Paused]);

    app(ImportStatusService::class)->resume($run);

    expect($run->fresh()->status)->toBe(ImportRunStatus::RunningRows);

    app(ImportStatusService::class)->cancel($run->fresh());

    expect($run->fresh()->status)->toBe(ImportRunStatus::Canceled)
        ->and($run->fresh()->finished_at)->not->toBeNull();
});

test('catalog import page latest logs are limited', function () {
    $run = ImportRun::factory()->create();

    foreach (range(1, 25) as $index) {
        app(ImportLogger::class)->info($run, 'log '.$index);
    }

    $logs = app(CatalogImportPage::class)->latestLogs($run, 8);

    expect($logs)->toHaveCount(8)
        ->and($logs->first()->message)->toBe('log 18')
        ->and($logs->last()->message)->toBe('log 25');
});

test('download original file works only for admin users', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/catalog/original.csv', importCsvContent());

    $run = ImportRun::factory()->create([
        'original_name' => 'original.csv',
        'stored_path' => 'imports/catalog/original.csv',
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]));
    expect(fn () => app(CatalogImportPage::class)->downloadOriginal($run->getKey()))->toThrow(HttpException::class);

    $this->actingAs(User::factory()->admin()->create());
    $response = app(CatalogImportPage::class)->downloadOriginal($run->getKey());

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
});

test('download logs and report return csv streams', function () {
    $this->actingAs(User::factory()->admin()->create());

    $run = ImportRun::factory()->create([
        'created_products' => 3,
        'updated_products' => 2,
    ]);

    app(ImportLogger::class)->warning($run, 'warning row', ['row' => 5]);

    expect(app(CatalogImportPage::class)->downloadLogs($run->getKey()))->toBeInstanceOf(StreamedResponse::class)
        ->and(app(CatalogImportPage::class)->downloadReport($run->getKey()))->toBeInstanceOf(StreamedResponse::class);
});

test('status waits for queued image jobs before done', function () {
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 10,
        'processed_rows' => 10,
        'queued_images' => 2,
        'processed_images' => 1,
        'failed_images' => 0,
    ]);

    app(ImportStatusService::class)->markRowsDone($run);

    expect($run->fresh()->status)->toBe(ImportRunStatus::ProcessingImages);

    app(ImportStatusService::class)->imageProcessed($run->fresh());

    expect($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and($run->fresh()->finished_at)->not->toBeNull();
});

test('failed image increments failed counter and writes warning without failing import', function () {
    Storage::fake('public');
    Http::fake([
        'https://example.test/broken.jpg' => Http::response('not-found', 404),
    ]);

    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::ProcessingImages,
        'queued_images' => 1,
        'processed_images' => 0,
        'failed_images' => 0,
    ]);
    $product = Product::factory()->create();

    (new DownloadProductImageJob($product->getKey(), 'https://example.test/broken.jpg', $run->getKey()))->handle(
        app(\App\Services\Import\ImportImageDownloader::class),
        app(ImportLogger::class),
        app(ImportStatusService::class),
    );

    expect($run->fresh()->failed_images)->toBe(1)
        ->and($run->fresh()->warnings_count)->toBe(1)
        ->and($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and(ImportLog::query()->where('level', ImportLogLevel::Warning->value)->where('message', 'like', '%изображение товара%')->exists())->toBeTrue();
});


test('admin import page opens and upload action creates import run', function () {
    Storage::fake('local');
    Queue::fake();

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin/imports/catalog')->assertOk();

    Livewire::test(CatalogImportPage::class)
        ->set('file', UploadedFile::fake()->createWithContent('catalog.csv', importCsvContent()))
        ->set('type', 'catalog')
        ->set('chunkSize', 100)
        ->set('startAfterUpload', true)
        ->call('submitImport')
        ->assertHasNoErrors();

    $run = ImportRun::query()->latest('id')->firstOrFail();

    expect($run->original_name)->toBe('catalog.csv')
        ->and($run->type)->toBe('catalog')
        ->and($run->chunk_size)->toBe(100);

    Storage::disk('local')->assertExists($run->stored_path);
    Queue::assertPushed(CatalogImportStartJob::class);
});

test('legacy upload action name is not exposed on import page', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin/imports/catalog')
        ->assertOk()
        ->assertSee('wire:submit.prevent="submitImport"', false)
        ->assertDontSee('wire:submit.prevent="upload"', false);
});
