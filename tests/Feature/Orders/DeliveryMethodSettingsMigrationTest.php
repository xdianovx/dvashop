<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('delivery method settings migration upgrades rolls back and upgrades without changing orders', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'delivery_method_settings_upgrade';

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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number');
            $table->decimal('total', 12, 2);
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('title_snapshot');
        });
        DB::table('orders')->insert(['id' => 91, 'number' => 'DVS-91', 'total' => 1250]);
        DB::table('order_items')->insert(['id' => 92, 'order_id' => 91, 'title_snapshot' => 'Порог']);

        $migration = require database_path('migrations/2026_08_05_000300_create_delivery_method_settings_table.php');
        $migration->up();

        expect(Schema::hasColumns('delivery_method_settings', [
            'id',
            'code',
            'title',
            'description',
            'base_price',
            'is_active',
            'position',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

        DB::table('delivery_method_settings')->insert([
            'code' => 'pickup',
            'title' => 'Самовывоз',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('delivery_method_settings')->insert([
            'code' => 'pickup',
            'title' => 'Дубликат',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasTable('delivery_method_settings'))->toBeFalse()
            ->and(DB::table('orders')->where('id', 91)->first())->not->toBeNull()
            ->and(DB::table('orders')->where('id', 91)->value('total'))->toBe(1250)
            ->and(DB::table('order_items')->where('id', 92)->value('title_snapshot'))->toBe('Порог');

        $migration->up();

        expect(Schema::hasTable('delivery_method_settings'))->toBeTrue()
            ->and(DB::table('delivery_method_settings')->count())->toBe(0)
            ->and(DB::table('orders')->where('id', 91)->value('number'))->toBe('DVS-91')
            ->and(DB::table('order_items')->where('id', 92)->value('title_snapshot'))->toBe('Порог');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
