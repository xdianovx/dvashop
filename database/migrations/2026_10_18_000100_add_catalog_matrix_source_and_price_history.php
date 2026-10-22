<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_matrix_sources', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->char('sha256', 64);
            $table->unsignedInteger('source_rows');
            $table->timestamp('installed_at');
        });

        Schema::create('catalog_matrix_price_history', function (Blueprint $table): void {
            $table->id();
            // Intentionally store immutable identifiers without cascading deletion of price history.
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('product_variant_id')->nullable()->index();
            $table->unsignedBigInteger('catalog_configuration_id')->nullable();
            $table->string('operation_ref', 80);
            $table->char('source_sha256', 64);
            $table->decimal('old_price', 12, 2)->nullable();
            $table->string('old_price_mode', 16)->nullable();
            $table->decimal('new_price', 12, 2);
            $table->string('new_price_mode', 16);
            $table->timestamp('created_at');
            $table->index(['operation_ref', 'product_id']);
        });
    }

    public function down(): void
    {
        // Existing price audit records must never be erased by a routine rollback.
        if (Schema::hasTable('catalog_matrix_price_history')
            && \Illuminate\Support\Facades\DB::table('catalog_matrix_price_history')->exists()) {
            throw new \RuntimeException('Price history is not empty: destructive rollback refused.');
        }
        Schema::dropIfExists('catalog_matrix_price_history');
        Schema::dropIfExists('catalog_matrix_sources');
    }
};
