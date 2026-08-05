<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('homepage sections migration upgrades rolls back and upgrades without changing unrelated data', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'homepage_sections_upgrade';
    config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('unrelated_records')->insert(['id' => 91, 'value' => 'keep']);
        $migration = require database_path('migrations/2026_08_05_000500_create_homepage_sections_table.php');
        $migration->up();

        expect(Schema::hasColumns('homepage_sections', ['id', 'code', 'title', 'is_active', 'position', 'created_at', 'updated_at']))->toBeTrue();
        DB::table('homepage_sections')->insert(['code' => 'quick_links', 'created_at' => now(), 'updated_at' => now()]);
        expect(fn () => DB::table('homepage_sections')->insert(['code' => 'quick_links', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);

        $migration->down();
        expect(Schema::hasTable('homepage_sections'))->toBeFalse()
            ->and(DB::table('unrelated_records')->where('id', 91)->value('value'))->toBe('keep');
        $migration->up();
        expect(Schema::hasTable('homepage_sections'))->toBeTrue()
            ->and(DB::table('homepage_sections')->count())->toBe(0)
            ->and(DB::table('unrelated_records')->where('id', 91)->value('value'))->toBe('keep');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
