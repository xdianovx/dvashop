<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('static page items migration enforces parent unique defaults indexes and rollback', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'static_page_items_upgrade';
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
        $items = require database_path('migrations/2026_08_05_001100_create_static_page_items_table.php');
        $pages->up();
        $sections->up();
        DB::table('static_pages')->insert(['id' => 10, 'code' => 'about', 'title' => 'О нас', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('static_page_sections')->insert(['id' => 20, 'static_page_id' => 10, 'code' => 'about_metrics', 'created_at' => now(), 'updated_at' => now()]);
        $items->up();

        expect(Schema::hasColumns('static_page_items', ['id', 'static_page_section_id', 'code', 'label', 'title', 'text', 'is_active', 'position', 'created_at', 'updated_at']))->toBeTrue();
        DB::table('static_page_items')->insert(['static_page_section_id' => 20, 'code' => 'about_metric_parts', 'title' => 'Детали', 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('static_page_items')->first();
        expect((int) $item->is_active)->toBe(1)->and($item->position)->toBe(0);
        expect(fn () => DB::table('static_page_items')->insert(['static_page_section_id' => 20, 'code' => 'about_metric_parts', 'title' => 'Дубль', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => DB::table('static_page_items')->insert(['static_page_section_id' => 999, 'code' => 'about_metric_models', 'title' => 'Нет', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => DB::table('static_page_sections')->where('id', 20)->delete())->toThrow(QueryException::class);
        $indexes = collect(DB::select("PRAGMA index_list('static_page_items')"))->pluck('name');
        expect($indexes)->toContain('static_page_items_code_unique')->toContain('static_page_items_static_page_section_id_is_active_position_index');

        $items->down();
        expect(Schema::hasTable('static_page_items'))->toBeFalse()
            ->and(Schema::hasTable('static_page_sections'))->toBeTrue()
            ->and(DB::table('unrelated_records')->value('value'))->toBe('keep');
        $items->up();
        expect(DB::table('static_page_items')->count())->toBe(0);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
