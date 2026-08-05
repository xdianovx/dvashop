<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('faq items migration enforces parent unique defaults indexes and rollback', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'faq_items_upgrade';
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
        $categories = require database_path('migrations/2026_08_05_001200_create_faq_categories_table.php');
        $items = require database_path('migrations/2026_08_05_001300_create_faq_items_table.php');
        $categories->up();
        DB::table('faq_categories')->insert(['id' => 10, 'code' => 'common', 'title' => 'Частые вопросы', 'created_at' => now(), 'updated_at' => now()]);
        $items->up();

        expect(Schema::hasColumns('faq_items', ['id', 'faq_category_id', 'code', 'question', 'answer', 'is_featured', 'is_active', 'position', 'created_at', 'updated_at', 'deleted_at']))->toBeTrue();
        DB::table('faq_items')->insert(['faq_category_id' => 10, 'code' => 'question_one', 'question' => 'Вопрос?', 'answer' => 'Ответ', 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('faq_items')->first();
        expect((int) $item->is_featured)->toBe(0)->and((int) $item->is_active)->toBe(1)->and($item->position)->toBe(0);
        expect(fn () => DB::table('faq_items')->insert(['faq_category_id' => 10, 'code' => 'question_one', 'question' => 'Дубль?', 'answer' => 'Нет', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => DB::table('faq_items')->insert(['faq_category_id' => 999, 'code' => 'question_two', 'question' => 'Нет?', 'answer' => 'Нет', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => DB::table('faq_categories')->where('id', 10)->delete())->toThrow(QueryException::class);
        $indexes = collect(DB::select("PRAGMA index_list('faq_items')"))->pluck('name');
        expect($indexes)->toContain('faq_items_code_unique')
            ->toContain('faq_items_faq_category_id_is_active_position_index')
            ->toContain('faq_items_is_featured_is_active_position_index');

        $items->down();
        expect(Schema::hasTable('faq_items'))->toBeFalse()
            ->and(Schema::hasTable('faq_categories'))->toBeTrue()
            ->and(DB::table('unrelated_records')->value('value'))->toBe('keep');
        $items->up();
        expect(DB::table('faq_items')->count())->toBe(0);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
