<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('promo migrations preserve historical finance and support isolated up down up', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'promo_upgrade';

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
        Schema::create('products', fn (Blueprint $table) => $table->id());
        Schema::create('product_categories', fn (Blueprint $table) => $table->id());
        Schema::create('part_types', fn (Blueprint $table) => $table->id());
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cart_id')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('total_snapshot', 12, 2);
        });

        $orderId = DB::table('orders')->insertGetId(['subtotal' => 1200, 'total' => 1200]);
        $itemId = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'total_snapshot' => 1200]);
        $promoMigration = require database_path('migrations/2026_09_01_000200_create_promo_codes_and_targets.php');
        $commerceMigration = require database_path('migrations/2026_09_01_000300_add_promotions_to_commerce.php');

        $promoMigration->up();
        $commerceMigration->up();

        $hasIndex = fn (string $table, array $columns, bool $unique = false): bool => collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => array_values($index['columns'] ?? []) === $columns
                && (! $unique || (bool) ($index['unique'] ?? false)));
        $hasForeign = fn (string $table, string $column, string $foreignTable): bool => collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreign): bool => in_array($column, $foreign['columns'] ?? [], true)
                && ($foreign['foreign_table'] ?? null) === $foreignTable);

        expect(Schema::hasTable('promo_codes'))->toBeTrue()
            ->and(Schema::hasTable('promo_code_products'))->toBeTrue()
            ->and(Schema::hasTable('promo_code_product_categories'))->toBeTrue()
            ->and(Schema::hasTable('promo_code_part_types'))->toBeTrue()
            ->and(Schema::hasTable('promo_code_redemptions'))->toBeTrue()
            ->and(Schema::hasColumns('carts', ['promo_code_id', 'promo_code_applied_at']))->toBeTrue()
            ->and(Schema::hasColumns('orders', [
                'promo_code_id', 'promo_code_snapshot', 'promo_name_snapshot',
                'promo_discount_type_snapshot', 'promo_discount_value_snapshot', 'discount_total',
            ]))->toBeTrue()
            ->and(Schema::hasColumns('order_items', ['discount_snapshot', 'final_total_snapshot']))->toBeTrue()
            ->and($hasIndex('promo_codes', ['code'], true))->toBeTrue()
            ->and($hasIndex('promo_codes', ['starts_at']))->toBeTrue()
            ->and($hasIndex('promo_codes', ['ends_at']))->toBeTrue()
            ->and($hasIndex('promo_codes', ['is_active', 'starts_at']))->toBeTrue()
            ->and($hasIndex('promo_code_products', ['promo_code_id', 'product_id'], true))->toBeTrue()
            ->and($hasIndex('promo_code_product_categories', ['promo_code_id', 'product_category_id'], true))->toBeTrue()
            ->and($hasIndex('promo_code_part_types', ['promo_code_id', 'part_type_id'], true))->toBeTrue()
            ->and($hasIndex('promo_code_redemptions', ['order_id'], true))->toBeTrue()
            ->and($hasIndex('promo_code_redemptions', ['promo_code_id', 'released_at']))->toBeTrue()
            ->and($hasForeign('promo_code_products', 'promo_code_id', 'promo_codes'))->toBeTrue()
            ->and($hasForeign('promo_code_products', 'product_id', 'products'))->toBeTrue()
            ->and($hasForeign('promo_code_product_categories', 'promo_code_id', 'promo_codes'))->toBeTrue()
            ->and($hasForeign('promo_code_product_categories', 'product_category_id', 'product_categories'))->toBeTrue()
            ->and($hasForeign('promo_code_part_types', 'promo_code_id', 'promo_codes'))->toBeTrue()
            ->and($hasForeign('promo_code_part_types', 'part_type_id', 'part_types'))->toBeTrue()
            ->and($hasForeign('promo_code_redemptions', 'promo_code_id', 'promo_codes'))->toBeTrue()
            ->and($hasForeign('promo_code_redemptions', 'order_id', 'orders'))->toBeTrue()
            ->and($hasForeign('carts', 'promo_code_id', 'promo_codes'))->toBeTrue()
            ->and($hasForeign('orders', 'promo_code_id', 'promo_codes'))->toBeTrue();

        $order = DB::table('orders')->where('id', $orderId)->first();
        $item = DB::table('order_items')->where('id', $itemId)->first();
        expect((float) $order->subtotal)->toBe(1200.0)
            ->and((float) $order->total)->toBe(1200.0)
            ->and((float) $order->discount_total)->toBe(0.0)
            ->and($order->promo_code_snapshot)->toBeNull()
            ->and((float) $item->discount_snapshot)->toBe(0.0)
            ->and((float) $item->final_total_snapshot)->toBe(1200.0);

        $commerceMigration->down();
        $promoMigration->down();

        expect(Schema::hasTable('promo_codes'))->toBeFalse()
            ->and(Schema::hasTable('promo_code_redemptions'))->toBeFalse()
            ->and(Schema::hasColumn('orders', 'discount_total'))->toBeFalse()
            ->and(Schema::hasColumn('order_items', 'final_total_snapshot'))->toBeFalse();

        $promoMigration->up();
        $commerceMigration->up();

        expect(Schema::hasTable('promo_codes'))->toBeTrue()
            ->and(Schema::hasTable('promo_code_redemptions'))->toBeTrue()
            ->and(Schema::hasColumn('orders', 'discount_total'))->toBeTrue()
            ->and((float) DB::table('orders')->where('id', $orderId)->value('discount_total'))->toBe(0.0)
            ->and(DB::table('orders')->where('id', $orderId)->value('promo_code_snapshot'))->toBeNull()
            ->and((float) DB::table('orders')->where('id', $orderId)->value('total'))->toBe(1200.0)
            ->and((float) DB::table('order_items')->where('id', $itemId)->value('discount_snapshot'))->toBe(0.0)
            ->and((float) DB::table('order_items')->where('id', $itemId)->value('final_total_snapshot'))->toBe(1200.0);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
