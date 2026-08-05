<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('payment method settings migration upgrades rolls back and upgrades without changing orders', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'payment_method_settings_upgrade';

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
            $table->string('sku_snapshot');
        });
        DB::table('orders')->insert(['id' => 81, 'number' => 'DVS-81', 'total' => 980]);
        DB::table('order_items')->insert(['id' => 82, 'order_id' => 81, 'sku_snapshot' => 'SKU-82']);

        $migration = require database_path('migrations/2026_08_05_000400_create_payment_method_settings_table.php');
        $migration->up();

        expect(Schema::hasColumns('payment_method_settings', [
            'id',
            'code',
            'title',
            'description',
            'is_active',
            'position',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

        DB::table('payment_method_settings')->insert([
            'code' => 'card',
            'title' => 'Банковская карта',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('payment_method_settings')->insert([
            'code' => 'card',
            'title' => 'Дубликат',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasTable('payment_method_settings'))->toBeFalse()
            ->and(DB::table('orders')->where('id', 81)->first())->not->toBeNull()
            ->and(DB::table('orders')->where('id', 81)->value('total'))->toBe(980)
            ->and(DB::table('order_items')->where('id', 82)->value('sku_snapshot'))->toBe('SKU-82');

        $migration->up();

        expect(Schema::hasTable('payment_method_settings'))->toBeTrue()
            ->and(DB::table('payment_method_settings')->count())->toBe(0)
            ->and(DB::table('orders')->where('id', 81)->value('number'))->toBe('DVS-81')
            ->and(DB::table('order_items')->where('id', 82)->value('sku_snapshot'))->toBe('SKU-82');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
