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
        $connection = DB::connection();

        return $this->connections[$connection] ??= $connection->getSchemaBuilder()->hasTable('catalog_price_locks');
    }

    public function forget(Connection $connection): void
    {
        unset($this->connections[$connection]);
    }
}
