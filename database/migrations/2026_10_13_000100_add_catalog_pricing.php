<?php

use Database\Seeders\CatalogBasePriceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_types', function (Blueprint $table): void {
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('base_old_price', 12, 2)->nullable();
        });
        foreach (['products', 'product_variants'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('price_mode', 16)->default('unknown'));
        }
        Schema::create('catalog_price_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action')->default('change');
            $table->boolean('classify_deviations')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->json('type_ids');
            $table->json('previous_bases');
            $table->decimal('price', 12, 2);
            $table->boolean('replace_all')->default(false);
            $table->json('overrides');
            $table->json('preview');
            $table->string('status')->default('pending')->index();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->json('report');
            $table->text('error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_price_items', function (Blueprint $table): void {
            $table->foreignId('operation_id')->constrained('catalog_price_operations')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->char('fingerprint', 64);
            $table->primary(['operation_id', 'product_id']);
        });
        Schema::create('catalog_price_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('catalog_price_operations')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('title');
            $table->json('previous_prices');
            $table->unique(['operation_id', 'product_id']);
        });
        Schema::create('catalog_price_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('operation_id')->nullable();
        });
        DB::table('catalog_price_locks')->insert(['id' => 1]);
        (new CatalogBasePriceSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_locks');
        Schema::dropIfExists('catalog_price_overrides');
        Schema::dropIfExists('catalog_price_items');
        Schema::dropIfExists('catalog_price_operations');
        foreach (['products', 'product_variants'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('price_mode'));
        }
        Schema::table('part_types', fn (Blueprint $table) => $table->dropColumn(['base_price', 'base_old_price']));
    }
};
