<?php

use App\Services\Catalog\CatalogImportDatabaseGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CatalogImportDatabaseGuards::class)->upgradeInventoryGuard();
    }

    public function down(): void
    {
        // Reintroducing the previous trigger would disable checkout and cancellation.
        // The original protection migration owns removal of all guard triggers.
    }
};
