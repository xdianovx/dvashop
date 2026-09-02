<?php

use App\Enums\PromoDiscountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('discount_type', 32)->default(PromoDiscountType::Percentage->value);
            $table->decimal('discount_value', 12, 4);
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->decimal('minimum_eligible_subtotal', 12, 2)->nullable();
            $table->boolean('applies_to_all')->default(true);
            $table->boolean('allow_sale_items')->default(false);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'starts_at']);
        });

        Schema::create('promo_code_products', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['promo_code_id', 'product_id']);
        });

        Schema::create('promo_code_product_categories', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->primary(['promo_code_id', 'product_category_id']);
        });

        Schema::create('promo_code_part_types', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('part_type_id')->constrained('part_types')->cascadeOnDelete();
            $table->primary(['promo_code_id', 'part_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_part_types');
        Schema::dropIfExists('promo_code_product_categories');
        Schema::dropIfExists('promo_code_products');
        Schema::dropIfExists('promo_codes');
    }
};
