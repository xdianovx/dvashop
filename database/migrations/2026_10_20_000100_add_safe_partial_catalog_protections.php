<?php

use App\Services\Catalog\CatalogImportDatabaseGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_import_protections', function (Blueprint $t): void {
            $t->foreignId('product_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignId('import_run_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('plan_version');
            $t->char('plan_hash', 64);
            $t->string('reason');
            $t->json('control');
            $t->timestamp('created_at');
        });
        Schema::create('catalog_safe_partial_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('import_run_id')->constrained()->restrictOnDelete();
            $t->string('incoming_key', 240);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->char('fingerprint', 64);
            $t->json('payload');
            $t->json('result')->nullable();
            $t->unique(['import_run_id', 'incoming_key'], 'safe_partial_run_key');
        });
        Schema::table('catalog_price_locks', fn (Blueprint $t) => $t->unsignedBigInteger('safe_partial_run_id')->nullable());
        app(CatalogImportDatabaseGuards::class)->install();
    }

    public function down(): void
    {
        if (DB::table('catalog_import_protections')->exists()) {
            throw new RuntimeException('Постоянная защита не снимается откатом миграции.');
        }
        app(CatalogImportDatabaseGuards::class)->uninstall();
        Schema::table('catalog_price_locks', fn (Blueprint $t) => $t->dropColumn('safe_partial_run_id'));
        Schema::dropIfExists('catalog_safe_partial_items');
        Schema::dropIfExists('catalog_import_protections');
    }
};
