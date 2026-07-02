<?php

use App\Enums\ImportLogLevel;
use App\Enums\ImportRunStatus;
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

test('start changes import status', function () {
    $run = ImportRun::factory()->create(['status' => ImportRunStatus::Ready]);

    app(ImportStatusService::class)->start($run);

    expect($run->fresh()->status)->toBe(ImportRunStatus::Running)
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

test('import logger writes logs', function () {
    $run = ImportRun::factory()->create();

    app(ImportLogger::class)->warning($run, 'Проверочное предупреждение', ['row' => 2]);

    $log = ImportLog::query()->firstOrFail();

    expect($log->import_run_id)->toBe($run->getKey())
        ->and($log->level)->toBe(ImportLogLevel::Warning)
        ->and($log->message)->toBe('Проверочное предупреждение')
        ->and($log->context)->toBe(['row' => 2]);
});
