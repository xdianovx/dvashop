<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('homepage category card destination migration upgrades rolls back and preserves catalog data', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'homepage_category_cards_upgrade';
    config(["database.connections.{$connection}" => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        Schema::create('part_types', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        DB::table('unrelated_records')->insert(['id' => 93, 'value' => 'keep']);
        DB::table('product_categories')->insert(['id' => 11, 'title' => 'Ремонтные элементы кузова']);
        DB::table('part_types')->insert(['id' => 12, 'title' => 'Порог']);

        $baseMigration = require database_path('migrations/2026_08_05_000700_create_homepage_category_cards_table.php');
        $destinationMigration = require database_path('migrations/2026_08_07_000100_add_catalog_destinations_to_homepage_category_cards_table.php');
        $baseMigration->up();
        $destinationMigration->up();

        expect(Schema::hasColumns('homepage_category_cards', [
            'id',
            'code',
            'title',
            'link_type',
            'route_name',
            'product_category_id',
            'part_type_id',
            'url',
            'open_in_new_tab',
            'is_active',
            'position',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

        DB::table('homepage_category_cards')->insert([
            'code' => 'sills',
            'title' => 'Пороги',
            'part_type_id' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('homepage_category_cards')->insert([
            'code' => 'body_repair',
            'title' => 'Кузов',
            'product_category_id' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        $destinationMigration->down();

        expect(Schema::hasColumn('homepage_category_cards', 'product_category_id'))->toBeFalse()
            ->and(Schema::hasColumn('homepage_category_cards', 'part_type_id'))->toBeFalse()
            ->and(DB::table('homepage_category_cards')->where('code', 'sills')->value('title'))->toBe('Пороги')
            ->and(DB::table('product_categories')->where('id', 11)->value('title'))->toBe('Ремонтные элементы кузова')
            ->and(DB::table('part_types')->where('id', 12)->value('title'))->toBe('Порог')
            ->and(DB::table('unrelated_records')->where('id', 93)->value('value'))->toBe('keep');

        $destinationMigration->up();

        expect(Schema::hasColumn('homepage_category_cards', 'product_category_id'))->toBeTrue()
            ->and(Schema::hasColumn('homepage_category_cards', 'part_type_id'))->toBeTrue()
            ->and(DB::table('homepage_category_cards')->where('code', 'sills')->value('title'))->toBe('Пороги');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
