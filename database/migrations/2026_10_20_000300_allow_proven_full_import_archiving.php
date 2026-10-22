<?php

use App\Services\Catalog\CatalogImportDatabaseGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CatalogImportDatabaseGuards::class)->upgradeProductArchiveGuard();
    }

    public function down(): void
    {
        // Preserve protection and the corrected importer contract; no data is removed.
    }
};
