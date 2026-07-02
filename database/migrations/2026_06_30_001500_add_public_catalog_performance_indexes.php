<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['status', 'slug'], 'products_status_slug_idx');
            $table->index(['product_category_id', 'status', 'position'], 'products_category_status_position_idx');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->index(['product_id', 'is_default', 'is_active'], 'variants_product_default_active_idx');
        });

        Schema::table('product_fitments', function (Blueprint $table): void {
            $table->index(['vehicle_generation_id', 'product_id'], 'fitments_generation_product_idx');
        });

        Schema::table('vehicle_makes', function (Blueprint $table): void {
            $table->index(['is_active', 'position'], 'vehicle_makes_active_position_idx');
            $table->index(['is_active', 'slug'], 'vehicle_makes_active_slug_idx');
            $table->index(['is_active', 'norm_key'], 'vehicle_makes_active_norm_key_idx');
        });

        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->index(['vehicle_make_id', 'is_active', 'position'], 'vehicle_models_make_active_position_idx');
            $table->index(['vehicle_make_id', 'is_active', 'slug'], 'vehicle_models_make_active_slug_idx');
            $table->index(['vehicle_make_id', 'is_active', 'norm_key'], 'vehicle_models_make_active_norm_key_idx');
        });

        Schema::table('vehicle_generations', function (Blueprint $table): void {
            $table->index(['vehicle_model_id', 'is_active', 'position'], 'vehicle_generations_model_active_position_idx');
            $table->index(['vehicle_model_id', 'is_active', 'slug'], 'vehicle_generations_model_active_slug_idx');
            $table->index(['vehicle_model_id', 'is_active', 'norm_key'], 'vehicle_generations_model_active_norm_key_idx');
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->index(['is_active', 'full_slug'], 'product_categories_active_full_slug_idx');
            $table->index(['parent_id', 'is_active', 'position'], 'product_categories_parent_active_position_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropIndex('product_categories_active_full_slug_idx');
            $table->dropIndex('product_categories_parent_active_position_idx');
        });

        Schema::table('vehicle_generations', function (Blueprint $table): void {
            $table->dropIndex('vehicle_generations_model_active_position_idx');
            $table->dropIndex('vehicle_generations_model_active_slug_idx');
            $table->dropIndex('vehicle_generations_model_active_norm_key_idx');
        });

        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->dropIndex('vehicle_models_make_active_position_idx');
            $table->dropIndex('vehicle_models_make_active_slug_idx');
            $table->dropIndex('vehicle_models_make_active_norm_key_idx');
        });

        Schema::table('vehicle_makes', function (Blueprint $table): void {
            $table->dropIndex('vehicle_makes_active_position_idx');
            $table->dropIndex('vehicle_makes_active_slug_idx');
            $table->dropIndex('vehicle_makes_active_norm_key_idx');
        });

        Schema::table('product_fitments', function (Blueprint $table): void {
            $table->dropIndex('fitments_generation_product_idx');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex('variants_product_default_active_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_status_slug_idx');
            $table->dropIndex('products_category_status_position_idx');
        });
    }
};
