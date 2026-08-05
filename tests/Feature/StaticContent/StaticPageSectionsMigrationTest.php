<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('static page sections migration enforces parent unique defaults indexes and rollback', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'static_page_sections_upgrade';
    config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('unrelated_records')->insert(['value' => 'keep']);
        $pages = require database_path('migrations/2026_08_05_000900_create_static_pages_table.php');
        $sections = require database_path('migrations/2026_08_05_001000_create_static_page_sections_table.php');
        $pages->up();
        DB::table('static_pages')->insert(['id' => 10, 'code' => 'about', 'title' => 'О нас', 'created_at' => now(), 'updated_at' => now()]);
        $sections->up();

        expect(Schema::hasColumns('static_page_sections', ['id', 'static_page_id', 'code', 'label', 'title', 'subtitle', 'body', 'is_active', 'position', 'created_at', 'updated_at']))->toBeTrue();
        DB::table('static_page_sections')->insert(['static_page_id' => 10, 'code' => 'about_hero', 'created_at' => now(), 'updated_at' => now()]);
        $section = DB::table('static_page_sections')->first();
        expect((int) $section->is_active)->toBe(1)->and($section->position)->toBe(0);
        expect(fn () => DB::table('static_page_sections')->insert(['static_page_id' => 10, 'code' => 'about_hero', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => DB::table('static_page_sections')->insert(['static_page_id' => 999, 'code' => 'about_goal', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => DB::table('static_pages')->where('id', 10)->delete())->toThrow(QueryException::class);
        $indexes = collect(DB::select("PRAGMA index_list('static_page_sections')"))->pluck('name');
        expect($indexes)->toContain('static_page_sections_code_unique')->toContain('static_page_sections_static_page_id_is_active_position_index');

        $sections->down();
        expect(Schema::hasTable('static_page_sections'))->toBeFalse()
            ->and(Schema::hasTable('static_pages'))->toBeTrue()
            ->and(DB::table('unrelated_records')->value('value'))->toBe('keep');
        $sections->up();
        expect(DB::table('static_page_sections')->count())->toBe(0);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
