<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function checkoutMigrationColumn(string $table, string $column): array
{
    return collect(Schema::getColumns($table))
        ->firstWhere('name', $column) ?? [];
}

function checkoutMigrationForeignKey(string $table, string $column): array
{
    return collect(Schema::getForeignKeys($table))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]) ?? [];
}

function useCheckoutMigrationMysqlConnection(): ?string
{
    $database = trim((string) env('CHECKOUT_MYSQL_DATABASE'));

    if ($database === '') {
        return null;
    }

    $originalConnection = DB::getDefaultConnection();
    $connection = 'checkout_migration_mysql';
    $configuration = config('database.connections.mysql');
    $configuration['database'] = $database;
    config(["database.connections.{$connection}" => $configuration]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    Artisan::call('migrate:fresh', [
        '--database' => $connection,
        '--force' => true,
    ]);

    return $originalConnection;
}

test('checkout migration rollback restores original nullability and foreign key actions', function () {
    $originalConnection = useCheckoutMigrationMysqlConnection();
    $migration = require database_path('migrations/2026_07_26_000300_add_checkout_snapshots_to_carts_and_orders.php');
    $rolledBack = false;

    try {
        expect(checkoutMigrationColumn('cart_items', 'product_variant_id')['nullable'])->toBeTrue()
            ->and(checkoutMigrationColumn('order_items', 'product_id')['nullable'])->toBeTrue()
            ->and(checkoutMigrationColumn('order_items', 'product_variant_id')['nullable'])->toBeTrue();

        $migration->down();
        $rolledBack = true;

        expect(Schema::hasColumn('cart_items', 'product_id'))->toBeFalse()
            ->and(Schema::hasColumn('order_items', 'title_snapshot'))->toBeFalse()
            ->and(checkoutMigrationColumn('cart_items', 'product_variant_id')['nullable'])->toBeFalse()
            ->and(checkoutMigrationColumn('order_items', 'product_id')['nullable'])->toBeFalse()
            ->and(checkoutMigrationColumn('order_items', 'product_variant_id')['nullable'])->toBeFalse()
            ->and(strtolower(checkoutMigrationForeignKey('cart_items', 'product_variant_id')['on_delete']))->toBe('cascade')
            ->and(strtolower(checkoutMigrationForeignKey('order_items', 'product_id')['on_delete']))->toBe('restrict')
            ->and(strtolower(checkoutMigrationForeignKey('order_items', 'product_variant_id')['on_delete']))->toBe('restrict');

        $migration->up();
        $rolledBack = false;

        expect(checkoutMigrationColumn('cart_items', 'product_variant_id')['nullable'])->toBeTrue()
            ->and(checkoutMigrationColumn('order_items', 'product_id')['nullable'])->toBeTrue()
            ->and(checkoutMigrationColumn('order_items', 'product_variant_id')['nullable'])->toBeTrue();
    } finally {
        if ($rolledBack) {
            $migration->up();
        }

        if ($originalConnection !== null) {
            DB::disconnect('checkout_migration_mysql');
            DB::setDefaultConnection($originalConnection);
            Schema::clearResolvedInstance('db.schema');
            config(['database.connections.checkout_migration_mysql' => null]);
        }
    }
});
