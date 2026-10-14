<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_import_snapshot_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('import_key', 240);
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->unique(['import_run_id', 'product_id'], 'catalog_snapshot_run_product_unique');
            $table->unique(['import_run_id', 'import_key'], 'catalog_snapshot_run_key_unique');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('last_import_price', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_snapshot_items');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('last_import_price'));
    }
};
