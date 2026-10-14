<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogPricesFixture;

// Disposable local browser fixture. Mount the project read-only into a separate
// container and publish its server only on 127.0.0.1. Never use as an application router.
require __DIR__.'/../../vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'true', 'APP_CONFIG_CACHE' => '/tmp/dvashop-pricing-ui/config.php',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'APP_URL' => 'http://127.0.0.1:18089',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/tmp/dvashop-pricing-ui/database.sqlite', 'DB_URL' => '',
    'SCOUT_DRIVER' => 'null', 'YANDEX_FEED_ENABLED' => 'false', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file',
    'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['', '/framework/views', '/framework/sessions', '/framework/cache', '/logs'] as $suffix) {
    if (! is_dir('/tmp/dvashop-pricing-ui'.$suffix)) {
        mkdir('/tmp/dvashop-pricing-ui'.$suffix, 0777, true);
    }
}
if (! is_file('/tmp/dvashop-pricing-ui/database.sqlite')) {
    touch('/tmp/dvashop-pricing-ui/database.sqlite');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath('/tmp/dvashop-pricing-ui');
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== '/tmp/dvashop-pricing-ui/database.sqlite') {
    throw new RuntimeException('Refusing to use a non-fixture database.');
}
app(Vite::class)->useHotFile('/tmp/dvashop-pricing-ui/no-vite-hot');
if (in_array('--seed', $argv ?? [], true)) {
    Artisan::call('migrate', ['--force' => true]);
    if (User::query()->exists()) {
        throw new RuntimeException('Fixture is already initialized');
    }
    User::factory()->superAdmin()->create(['name' => 'Тест интерфейса', 'email' => 'pricing@example.test']);
    CatalogPricesFixture::create();
    echo "1949 products / 46776 variants ready in disposable SQLite\n";
    exit;
}
if (in_array('--work', $argv ?? [], true)) {
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3]);
    echo Artisan::output();
    exit;
}
if (PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('Use the isolated PHP development server');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../public'.$path)) {
    return false;
}
Route::middleware('web')->get('/__pricing-login', function () {
    Auth::loginUsingId(User::query()->firstOrFail()->id);

    return redirect('/admin/catalog/prices');
});
$app->handleRequest(Request::capture());
