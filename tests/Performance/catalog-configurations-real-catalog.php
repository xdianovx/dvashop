<?php

// Reads and mutates ONLY an explicitly restored, disposable copy of the real catalog.
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\ImportRun;
use App\Enums\ImportRunStatus;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogActions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
foreach (['APP_ENV'=>'testing', 'APP_CONFIG_CACHE'=>'/tmp/dvashop-audit-no-config.php',
    'LARAVEL_STORAGE_PATH'=>'/tmp/dvashop-real-audit', 'DB_CONNECTION'=>'mysql', 'DB_HOST'=>'dvashop-config-audit-test',
    'DB_DATABASE'=>'dvashop_audit_test','DB_USERNAME'=>'root','DB_PASSWORD'=>'audit-test-only','DB_URL'=>'',
    'SCOUT_DRIVER'=>'null','YANDEX_FEED_ENABLED'=>'false','QUEUE_CONNECTION'=>'sync','CACHE_STORE'=>'array',
    'SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','TELESCOPE_ENABLED'=>'false','PULSE_ENABLED'=>'false',
    'NIGHTWATCH_ENABLED'=>'false','SHOP_ORDERS_BITRIX_ENABLED'=>'false','SHOP_INQUIRIES_BITRIX_ENABLED'=>'false'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value;
}
foreach (['/framework/views','/framework/cache/data','/framework/sessions','/logs','/app/private'] as $suffix) {
    if (!is_dir('/tmp/dvashop-real-audit'.$suffix)) { mkdir('/tmp/dvashop-real-audit'.$suffix,0777,true); }
}
$app=require __DIR__.'/../../bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || DB::getDriverName()!=='mysql'
    || config('database.connections.mysql.host')!=='dvashop-config-audit-test' || DB::connection()->getDatabaseName()!=='dvashop_audit_test') {
    throw new RuntimeException('Refusing any database except the isolated restored audit copy.');
}
Bus::fake(); Queue::fake(); Http::preventStrayRequests();
config(['scout.driver'=>null,'yandex-feed.enabled'=>false]);
function auditCheck(bool $condition,string $message):void { if (!$condition) { throw new RuntimeException($message); } }
function auditOutput(string $stage,array $data):void { echo json_encode(['stage'=>$stage]+$data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL; }
function auditHash(string $table):string {
    $hash=hash_init('sha256');
    foreach (DB::table($table)->orderBy('id')->cursor() as $row) { hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)); }
    return hash_final($hash);
}
$phase=$argv[1]??'prepare';
if ($phase==='prepare') {
    auditCheck(!DB::getSchemaBuilder()->hasTable('audit_original_variants'),'Copy was already prepared');
    $counts=[];foreach (['products','product_variants','orders','order_items'] as $table) {$counts[$table]=DB::table($table)->count();}
    auditOutput('real_baseline',$counts);
    DB::statement('CREATE TABLE audit_original_variants AS SELECT id, product_id, price, price_mode, old_price, is_active, is_default FROM product_variants');
    DB::statement('ALTER TABLE audit_original_variants ADD PRIMARY KEY (id)');
    DB::statement('CREATE TABLE audit_original_products AS SELECT id, slug, part_type_id, price, price_mode, old_price, status FROM products');
    DB::statement('ALTER TABLE audit_original_products ADD PRIMARY KEY (id)');
    DB::statement('CREATE TABLE audit_checks (name varchar(100) primary key, value text)');
    foreach (['orders','order_items'] as $table) { DB::table('audit_checks')->insert(['name'=>$table,'value'=>auditHash($table)]); }
    Artisan::call('migrate',['--force'=>true]);
    auditOutput('schema',['exit'=>Artisan::output()]);
    app(CatalogConfigurations::class)->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    $admin=User::factory()->superAdmin()->create(['email'=>'isolated-catalog-audit@example.test']);
    DB::table('audit_checks')->insert(['name'=>'admin','value'=>(string)$admin->id]);
}
if (in_array($phase,['prepare','configurations'])) {
    $admin=User::findOrFail(DB::table('audit_checks')->where('name','admin')->value('value'));
    $service=app(CatalogActions::class);
    $types=DB::table('catalog_configurations')->distinct()->orderBy('part_type_id')->pluck('part_type_id');
    foreach ($types as $type) {
        $parameters=['kind'=>'configurations','type_id'=>$type,'descendants'=>false,'source_status'=>'all','force'=>false];
        $existing=DB::table('catalog_actions')->where('kind','configurations')->where('parameters->type_id',$type)->first();
        if ($existing && $existing->status==='done') { continue; }
        $preview=$service->preview($admin,$parameters);
        auditOutput('configuration_preview',['type'=>$type]+$preview['counts']);
        $id=$existing?->id??$service->confirm($admin,$preview);
        $batch=0;
        while (in_array(($op=DB::table('catalog_actions')->find($id))->status,['pending','running'])) {
            $service->batch($id,(int)$op->last_id);
            if (++$batch%10===0) {auditOutput('configuration_progress',['type'=>$type,'batches'=>$batch]);}
        }
        $repeat=$service->preview($admin,$parameters);
        auditCheck($repeat['counts']['changed']===0,'Repeated configuration application is not a no-op');
        auditOutput('configuration_done',['type'=>$type,'repeat_changed'=>$repeat['counts']['changed']]);
    }
    auditCheck(DB::selectOne('SELECT count(*) n FROM audit_original_variants a LEFT JOIN product_variants v ON v.id=a.id WHERE v.id IS NULL')->n===0,'Old variant ID lost');
    auditCheck(DB::selectOne("SELECT count(*) n FROM audit_original_variants a JOIN product_variants v ON v.id=a.id WHERE a.price_mode IN ('individual','unknown') AND (NOT (a.price <=> v.price) OR NOT (a.old_price <=> v.old_price))")->n===0,'Protected variant price changed');
    auditCheck(DB::selectOne('SELECT count(*) n FROM audit_original_products a LEFT JOIN products p ON p.id=a.id WHERE p.id IS NULL OR p.slug<>a.slug OR p.status<>a.status')->n===0,'Configuration application changed product identity or status');
    auditOutput('configurations_verified',['products'=>Product::count(),'variants'=>ProductVariant::count(),'old_variant_ids'=>DB::table('audit_original_variants')->count(),'protected_prices'=>DB::table('audit_original_variants')->whereIn('price_mode',['unknown','individual'])->count()]);
}
if ($phase==='import') {
    $source='/tmp/dvashop-current-auto-base.xlsx'; auditCheck(is_file($source),'Current source XLSX missing');
    $destination=Storage::disk('local')->path('actual.xlsx');copy($source,$destination);
    for ($pass=1;$pass<=2;$pass++) {
        DB::statement('DROP TABLE IF EXISTS audit_before_import');
        DB::statement('CREATE TABLE audit_before_import AS SELECT id,slug,status FROM products');
        DB::statement('ALTER TABLE audit_before_import ADD PRIMARY KEY(id)');
        $variantCount=ProductVariant::count();
        $run=app(App\Services\ImportStatusService::class)->createFromStoredPath('catalog','actual.xlsx','actual.xlsx',chunkSize:100);
        app()->call([new App\Jobs\CatalogImportStartJob($run->id),'handle']);
        while ($run->refresh()->status===ImportRunStatus::RunningRows) {
            app()->call([new App\Jobs\CatalogImportChunkJob($run->id),'handle']);
            auditOutput('import_progress',['pass'=>$pass,'processed'=>$run->fresh()->processed_rows]);
        }
        auditCheck($run->refresh()->errors_count===0 && in_array($run->status,[ImportRunStatus::Done,ImportRunStatus::ProcessingImages]),'Import did not publish: '.($run->last_error??$run->status->value));
        $actual=DB::selectOne("SELECT SUM(a.id IS NULL) created, SUM(a.status='archived' AND p.status='active') reactivated, SUM(a.status<>'archived' AND p.status='archived') archived FROM products p LEFT JOIN audit_before_import a ON a.id=p.id");
        auditCheck(DB::selectOne('SELECT count(*) n FROM audit_before_import a LEFT JOIN products p ON p.id=a.id WHERE p.id IS NULL OR p.slug<>a.slug')->n===0,'Existing product identity changed');
        auditCheck(Product::where('status','active')->count()===17739,'Unexpected active snapshot size');
        if ($pass===2) {auditCheck((int)$actual->created===0 && ProductVariant::count()===$variantCount,'Repeated import created duplicates');}
        auditOutput('import_done',['pass'=>$pass,'products'=>Product::count(),'variants'=>ProductVariant::count(),'active'=>Product::where('status','active')->count()]+(array)$actual);
        // Image jobs are deliberately not executed; status authority has already committed.
        $run->forceFill(['status'=>ImportRunStatus::Done])->save();
    }
}
foreach (['orders','order_items'] as $table) {
    auditCheck(hash_equals(DB::table('audit_checks')->where('name',$table)->value('value'),auditHash($table)),'Historical '.$table.' changed');
}
auditCheck(DB::selectOne('SELECT count(*) n FROM audit_original_variants a LEFT JOIN product_variants v ON v.id=a.id WHERE v.id IS NULL')->n===0,'Historical variant removed');
auditCheck(DB::selectOne("SELECT count(*) n FROM audit_original_variants a JOIN product_variants v ON v.id=a.id WHERE a.price_mode IN ('individual','unknown') AND (NOT (a.price <=> v.price) OR NOT (a.old_price <=> v.old_price))")->n===0,'Historical price overwritten');
auditOutput('PASS',['phase'=>$phase,'orders_unchanged'=>true,'order_items_unchanged'=>true,'variant_ids_preserved'=>true,'protected_prices_preserved'=>true]);
