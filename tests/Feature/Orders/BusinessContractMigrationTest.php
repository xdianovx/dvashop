<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('delivery price and inventory contracts use a reversible forward migration with safe historical backfill', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'business_contract_upgrade';

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
        Schema::create('delivery_method_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->decimal('base_price', 12, 2)->default(0);
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_method');
            $table->text('delivery_method_description_snapshot')->nullable();
            $table->decimal('delivery_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedInteger('quantity');
        });

        DB::table('delivery_method_settings')->insert([
            ['code' => DeliveryMethod::Pickup->value, 'base_price' => 0],
            ['code' => DeliveryMethod::TransportCompany->value, 'base_price' => 0],
            ['code' => DeliveryMethod::Courier->value, 'base_price' => 550],
        ]);
        DB::table('orders')->insert([
            ['delivery_method' => DeliveryMethod::Pickup->value, 'delivery_price' => 0, 'total' => 1000],
            ['delivery_method' => DeliveryMethod::TransportCompany->value, 'delivery_price' => 0, 'total' => 2000],
            ['delivery_method' => DeliveryMethod::Courier->value, 'delivery_price' => 550, 'total' => 3550],
        ]);

        $migration = require database_path('migrations/2026_08_10_000100_add_delivery_price_and_inventory_contracts.php');
        $migration->up();

        expect(Schema::hasColumn('delivery_method_settings', 'price_mode'))->toBeTrue()
            ->and(Schema::hasColumns('orders', ['delivery_price_mode_snapshot', 'total_is_final']))->toBeTrue()
            ->and(Schema::hasColumns('order_items', ['stock_was_decremented', 'stock_restored_at']))->toBeTrue()
            ->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::Pickup)->value('price_mode'))->toBe(DeliveryPriceMode::Free->value)
            ->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::TransportCompany)->value('price_mode'))->toBe(DeliveryPriceMode::OnRequest->value)
            ->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::Courier)->value('price_mode'))->toBe(DeliveryPriceMode::Fixed->value)
            ->and(DB::table('orders')->where('delivery_method', DeliveryMethod::TransportCompany)->first())->toMatchObject([
                'delivery_price_mode_snapshot' => DeliveryPriceMode::OnRequest->value,
                'total_is_final' => 0,
            ]);

        $migration->down();

        expect(Schema::hasColumn('delivery_method_settings', 'price_mode'))->toBeFalse()
            ->and(Schema::hasColumn('orders', 'total_is_final'))->toBeFalse()
            ->and(Schema::hasColumn('order_items', 'stock_was_decremented'))->toBeFalse();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
