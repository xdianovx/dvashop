<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('site navigation migration upgrades rolls back and upgrades without changing existing data', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'site_navigation_upgrade';

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('existing_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('existing_records')->insert(['id' => 1, 'value' => 'Сохранить']);

        $migration = require database_path('migrations/2026_08_05_000200_create_site_navigation_items_table.php');
        $migration->up();

        expect(Schema::hasColumns('site_navigation_items', [
            'id',
            'code',
            'zone',
            'title',
            'link_type',
            'route_name',
            'url',
            'open_in_new_tab',
            'is_active',
            'position',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

        DB::table('site_navigation_items')->insert([
            'code' => 'about',
            'zone' => 'footer_about',
            'title' => 'О нас',
            'link_type' => 'route',
            'route_name' => 'about',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        expect(Schema::hasTable('site_navigation_items'))->toBeFalse()
            ->and(DB::table('existing_records')->where('id', 1)->value('value'))->toBe('Сохранить');

        $migration->up();

        expect(Schema::hasTable('site_navigation_items'))->toBeTrue()
            ->and(DB::table('site_navigation_items')->count())->toBe(0)
            ->and(DB::table('existing_records')->where('id', 1)->value('value'))->toBe('Сохранить');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
