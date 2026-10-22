<?php

use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportRun;
use App\Services\Import\ImportImageRecovery;
use App\Services\Import\ImportImageTasks;
use Illuminate\Contracts\Console\Kernel;

$earlyInput = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
file_put_contents($earlyInput['ready'].'.phase', 'autoload');
require __DIR__.'/../../vendor/autoload.php';
$settings = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
foreach ($settings as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
file_put_contents($earlyInput['ready'].'.phase', 'database');
if (! app()->environment('testing') || DB::connection()->getConfig('host') !== 'dvashop_safe_partial_mysql_fix'
    || DB::connection()->getDatabaseName() !== 'dvashop_guard_test') {
    throw new RuntimeException('Isolated test database required');
}
$input = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 30;
while (! is_file($input['start'])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Race start barrier timed out');
    }
    usleep(20000);
}
if ($input['action'] === 'complete') {
    $job = new DownloadVehicleGenerationImageJob($input['generation'], $input['url'], $input['run']);
    app(ImportImageTasks::class)->complete($job, 'vehicle', true, [], $input['token']);
} else {
    app(ImportImageRecovery::class)->recover(ImportRun::findOrFail($input['run']));
}
echo 'ok';
