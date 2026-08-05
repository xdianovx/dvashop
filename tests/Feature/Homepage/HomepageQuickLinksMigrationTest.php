<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('homepage quick links migration upgrades rolls back and upgrades without changing unrelated data', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'homepage_quick_links_upgrade';
    config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('unrelated_records')->insert(['id' => 92, 'value' => 'keep']);
        $migration = require database_path('migrations/2026_08_05_000600_create_homepage_quick_links_table.php');
        $migration->up();

        expect(Schema::hasColumns('homepage_quick_links', ['id', 'code', 'title', 'link_type', 'route_name', 'url', 'open_in_new_tab', 'is_active', 'position', 'created_at', 'updated_at']))->toBeTrue();
        DB::table('homepage_quick_links')->insert(['code' => 'new_arrivals', 'title' => 'Новинки', 'created_at' => now(), 'updated_at' => now()]);
        expect(fn () => DB::table('homepage_quick_links')->insert(['code' => 'new_arrivals', 'title' => 'Дубликат', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);

        $migration->down();
        expect(Schema::hasTable('homepage_quick_links'))->toBeFalse()
            ->and(DB::table('unrelated_records')->where('id', 92)->value('value'))->toBe('keep');
        $migration->up();
        expect(Schema::hasTable('homepage_quick_links'))->toBeTrue()
            ->and(DB::table('homepage_quick_links')->count())->toBe(0)
            ->and(DB::table('unrelated_records')->where('id', 92)->value('value'))->toBe('keep');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
