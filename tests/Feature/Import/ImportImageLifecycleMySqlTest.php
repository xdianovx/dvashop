<?php

use App\Enums\ImportRunStatus;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use App\Services\Import\ImportImageRecovery;
use App\Services\Import\ImportImageTasks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires isolated MySQL.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::connection()->getConfig('host'))->toBe('dvashop_safe_partial_mysql_fix')
        ->and(DB::connection()->getDatabaseName())->toBe('dvashop_guard_test');
    if (! ($GLOBALS['image_lifecycle_mysql_schema'] ?? false)) {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $GLOBALS['image_lifecycle_mysql_schema'] = true;
    }
    config(['queue.default' => 'database']);
});

function mysqlImageTask(): array
{
    $run = ImportRun::factory()->create(['status' => ImportRunStatus::ProcessingImages, 'total_rows' => 1, 'processed_rows' => 1,
        'queued_images' => 0, 'processed_images' => 0, 'failed_images' => 0]);
    $g = VehicleGeneration::factory()->create();
    $job = new DownloadVehicleGenerationImageJob($g->id, 'https://example.test/'.$g->id.'.jpg', $run->id);
    app(ImportImageTasks::class)->enqueue($run, 'vehicle', $g->id, $job->url);

    return [$run->fresh(), $job];
}

function imageLifecycleRace(array $input): void
{
    $directory = sys_get_temp_dir().'/image-lifecycle-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $c = DB::connection()->getConfig();
    $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_CONFIG_CACHE' => $directory.'/no-config.php',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $c['host'], 'DB_DATABASE' => $c['database'], 'DB_PORT' => (string) $c['port'],
        'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'DB_URL' => '', 'DB_SOCKET' => '',
        'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array', 'SCOUT_DRIVER' => 'null', 'CATALOG_SEARCH_DRIVER' => 'database',
        'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'];
    file_put_contents($directory.'/env.json', json_encode($env, JSON_THROW_ON_ERROR));
    chmod($directory.'/env.json', 0600);
    $processes = [];
    try {
        foreach ([0, 1] as $i) {
            $worker = $input + ['ready' => "$directory/ready$i", 'start' => "$directory/start"];
            $processes[] = proc_open([PHP_BINARY, base_path('tests/Support/ImportImageLifecycleWorker.php'), $directory.'/env.json', json_encode($worker)],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$directory/out$i", 'w'], 2 => ['file', "$directory/err$i", 'w']], $pipes, base_path());
        }
        // Do not count PHP/application boot time as database lock contention.
        $readyDeadline = microtime(true) + 25;
        while (! is_file("$directory/ready0") || ! is_file("$directory/ready1")) {
            if (microtime(true) >= $readyDeadline) {
                $diagnostics = [];
                foreach ($processes as $i => $process) {
                    $status = proc_get_status($process);
                    $diagnostics[] = ['phase' => @file_get_contents("$directory/ready$i.phase"), 'running' => $status['running'],
                        'exitcode' => $status['exitcode'], 'stderr' => file_get_contents("$directory/err$i")];
                }
                throw new RuntimeException('Workers did not reach race barrier: '.json_encode($diagnostics));
            }
            usleep(20000);
        }
        DB::beginTransaction();
        DB::table('import_runs')->where('id', $input['run'])->lockForUpdate()->first();
        file_put_contents("$directory/start", 'start');
        $deadline = microtime(true) + 20;
        do {
            $waiting = (int) DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits')->n;
            if ($waiting >= 2) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $diagnostic = '';
        foreach ([0, 1] as $i) {
            $diagnostic .= file_get_contents("$directory/err$i").file_get_contents("$directory/out$i");
        }
        expect($waiting)->toBeGreaterThanOrEqual(2, $diagnostic);
        DB::commit();
        foreach ($processes as $i => $process) {
            expect(proc_close($process))->toBe(0, file_get_contents("$directory/err$i"));
            expect(file_get_contents("$directory/out$i"))->toBe('ok');
        }
    } finally {
        if (DB::transactionLevel()) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('mysql image result and counter remain invisible until commit and recovery waits for that transaction', function (): void {
    [$run, $job] = mysqlImageTask();
    $claim = app(ImportImageTasks::class)->start($job, 'vehicle');
    $default = DB::getDefaultConnection();
    $observerConfig = DB::connection()->getConfig();
    unset($observerConfig['name']);
    config(['database.connections.image_lifecycle_observer' => $observerConfig]);
    DB::beginTransaction();
    try {
        app(ImportImageTasks::class)->complete($job, 'vehicle', true, [], $claim['token']);
        DB::setDefaultConnection('image_lifecycle_observer');
        DB::statement('SET SESSION innodb_lock_wait_timeout=1');
        $observer = ImportRun::findOrFail($run->id);
        expect($observer->processed_images)->toBe(0)->and($observer->status)->toBe(ImportRunStatus::ProcessingImages)
            ->and(app(ImportImageRecovery::class)->inspect($observer)['counts'])->toBe(['running' => 1]);
        expect(fn () => app(ImportImageRecovery::class)->recover($observer))->toThrow(QueryException::class);
    } finally {
        DB::setDefaultConnection($default);
        DB::rollBack();
        DB::purge('image_lifecycle_observer');
    }
    expect($run->fresh()->processed_images)->toBe(0);
    app(ImportImageTasks::class)->complete($job, 'vehicle', true, [], $claim['token']);
    expect($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Done);
});

test('two concurrent mysql result commits count one image only', function (): void {
    [$run, $job] = mysqlImageTask();
    $claim = app(ImportImageTasks::class)->start($job, 'vehicle');
    imageLifecycleRace(['action' => 'complete', 'run' => $run->id, 'generation' => $job->vehicleGenerationId, 'url' => $job->url, 'token' => $claim['token']]);
    expect($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and($run->logs()->whereNotNull('context->vehicle_image_result_key')->count())->toBe(1);
});

test('two concurrent mysql recoveries enqueue one replacement for a lost task', function (): void {
    [$run, $job] = mysqlImageTask();
    DB::table('jobs')->delete();
    $log = $run->logs()->whereNotNull('context->image_task')->first();
    app(ImportImageTasks::class)->updateTask($log, $log->context['image_task'], ['last_activity' => now()->subHour()->timestamp]);
    imageLifecycleRace(['action' => 'recover', 'run' => $run->id]);
    expect(DB::table('jobs')->count())->toBe(1)->and($run->fresh()->queued_images)->toBe(1)->and($run->fresh()->status)->toBe(ImportRunStatus::ProcessingImages);
});
