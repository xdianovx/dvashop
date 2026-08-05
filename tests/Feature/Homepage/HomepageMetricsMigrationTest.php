<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('homepage metrics migration upgrades rolls back and upgrades without changing unrelated data', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'homepage_metrics_upgrade';
    config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('unrelated_records')->insert(['id' => 94, 'value' => 'keep']);
        $migration = require database_path('migrations/2026_08_05_000800_create_homepage_metrics_table.php');
        $migration->up();

        expect(Schema::hasColumns('homepage_metrics', ['id', 'code', 'prefix', 'value', 'suffix', 'text', 'is_active', 'position', 'created_at', 'updated_at']))->toBeTrue();
        DB::table('homepage_metrics')->insert(['code' => 'since_year', 'value' => '2014', 'text' => 'Экспертиза', 'created_at' => now(), 'updated_at' => now()]);
        expect(fn () => DB::table('homepage_metrics')->insert(['code' => 'since_year', 'value' => '1', 'text' => 'Дубликат', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);

        $migration->down();
        expect(Schema::hasTable('homepage_metrics'))->toBeFalse()
            ->and(DB::table('unrelated_records')->where('id', 94)->value('value'))->toBe('keep');
        $migration->up();
        expect(Schema::hasTable('homepage_metrics'))->toBeTrue()
            ->and(DB::table('homepage_metrics')->count())->toBe(0)
            ->and(DB::table('unrelated_records')->where('id', 94)->value('value'))->toBe('keep');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
