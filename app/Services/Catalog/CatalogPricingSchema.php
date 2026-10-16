<?php

namespace App\Services\Catalog;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use WeakMap;

final class CatalogPricingSchema
{
    private WeakMap $connections;

    public function __construct()
    {
        $this->connections = new WeakMap;
    }

    public function ready(): bool
    {
        $ready = $this->has('catalog_price_locks');
        // Prime the optional extension alongside the existing catalog guard.
        $this->has('catalog_configurations');

        return $ready;
    }

    public function has(string $table): bool
    {
        $connection = DB::connection();
        $tables = $this->connections[$connection] ?? [];
        $tables[$table] ??= $connection->getSchemaBuilder()->hasTable($table);
        $this->connections[$connection] = $tables;

        return $tables[$table];
    }

    public function forget(Connection $connection): void
    {
        unset($this->connections[$connection]);
    }
}
