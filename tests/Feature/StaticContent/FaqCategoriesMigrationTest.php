<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('faq categories migration upgrades rolls back and preserves unrelated data', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'faq_categories_upgrade';
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
        $migration = require database_path('migrations/2026_08_05_001200_create_faq_categories_table.php');
        $migration->up();

        expect(Schema::hasColumns('faq_categories', ['id', 'code', 'title', 'is_active', 'position', 'created_at', 'updated_at', 'deleted_at']))->toBeTrue();
        DB::table('faq_categories')->insert(['code' => 'common', 'title' => 'Частые вопросы', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('faq_categories')->first();
        expect((int) $category->is_active)->toBe(1)->and($category->position)->toBe(0)->and($category->deleted_at)->toBeNull();
        expect(fn () => DB::table('faq_categories')->insert(['code' => 'common', 'title' => 'Дубль', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        $indexes = collect(DB::select("PRAGMA index_list('faq_categories')"))->pluck('name');
        expect($indexes)->toContain('faq_categories_code_unique')->toContain('faq_categories_is_active_position_index');

        $migration->down();
        expect(Schema::hasTable('faq_categories'))->toBeFalse()
            ->and(DB::table('unrelated_records')->value('value'))->toBe('keep');
        $migration->up();
        expect(DB::table('faq_categories')->count())->toBe(0);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
