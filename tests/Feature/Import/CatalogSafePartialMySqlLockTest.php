<?php

use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Catalog\CatalogPriceGuard;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

test('MySQL protection reads are current across old snapshots and serialize competing writers', function (): void {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the separately isolated MySQL test container.');
    }
    $config = DB::connection()->getConfig();
    // Never run this destructive fixture setup against an application database.
    expect(app()->environment('testing'))->toBeTrue();
    expect($config['database'])->toBe('dvashop_guard_test');
    expect($config['host'])->toStartWith('dvashop_safe_partial_mysql_');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    Bus::fake();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    $matrix = app(CatalogConfigurations::class);
    $matrix->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    $type = PartType::where('full_slug', 'usilitel')->firstOrFail();
    $product = Product::factory()->create(['part_type_id' => $type->id, 'import_source' => 'catalog']);
    $matrix->synchronize($product);
    $configuration = $product->variants()->firstOrFail()->catalog_configuration_id;
    $run = ImportRun::factory()->create(['import_mode' => 'safe_partial']);
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $other->beginTransaction();
    expect((int) $other->query('SELECT COUNT(*) FROM catalog_import_protections')->fetchColumn())->toBe(0);
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$product->id => 'test'], 1, str_repeat('a', 64)));
    // The old consistent snapshot cannot hide a newly committed protection from the trigger.
    expect(fn () => $other->exec('UPDATE catalog_configurations SET price=price+1 WHERE id='.(int) $configuration))
        ->toThrow(PDOException::class, 'catalog import protection');
    $other->rollBack();

    DB::beginTransaction();
    try {
        app(CatalogPriceGuard::class)->lock();
        $other->beginTransaction();
        try {
            expect(fn () => $other->exec('UPDATE catalog_configurations SET price=price+1 WHERE id='.(int) $configuration))
                ->toThrow(PDOException::class, 'Lock wait timeout');
        } finally {
            $other->rollBack();
        }
    } finally {
        DB::rollBack();
    }
    expect(fn () => $other->exec('UPDATE catalog_configurations SET price=price+1 WHERE id='.(int) $configuration))
        ->toThrow(PDOException::class, 'catalog import protection');
});
