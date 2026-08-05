<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('static pages migration upgrades rolls back and upgrades without business data', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'static_pages_upgrade';
    config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('unrelated_records')->insert(['id' => 1, 'value' => 'keep']);
        $migration = require database_path('migrations/2026_08_05_000900_create_static_pages_table.php');
        $migration->up();

        expect(Schema::hasColumns('static_pages', ['id', 'code', 'title', 'subtitle', 'primary_action_label', 'secondary_action_label', 'is_active', 'position', 'created_at', 'updated_at']))->toBeTrue();
        DB::table('static_pages')->insert(['code' => 'about', 'title' => 'О нас', 'created_at' => now(), 'updated_at' => now()]);
        $page = DB::table('static_pages')->first();
        expect((int) $page->is_active)->toBe(1)->and($page->position)->toBe(0);
        expect(fn () => DB::table('static_pages')->insert(['code' => 'about', 'title' => 'Дубль', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        $indexes = collect(DB::select("PRAGMA index_list('static_pages')"))->pluck('name');
        expect($indexes)->toContain('static_pages_code_unique')->toContain('static_pages_is_active_position_index');

        $migration->down();
        expect(Schema::hasTable('static_pages'))->toBeFalse()
            ->and(DB::table('unrelated_records')->value('value'))->toBe('keep');
        $migration->up();
        expect(DB::table('static_pages')->count())->toBe(0);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
