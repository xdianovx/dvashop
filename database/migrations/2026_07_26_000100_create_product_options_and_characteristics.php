<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('input_type')->default('radio');
            $table->string('applies_to')->default('all')->index();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_option_group_id', 'slug'], 'option_values_group_slug_unique');
            $table->unique(['product_option_group_id', 'code'], 'option_values_group_code_unique');
            $table->index(['product_option_group_id', 'position'], 'option_values_group_position_idx');
        });

        Schema::create('product_option_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('applies_to')->default('auto_part')->index();
            $table->foreignId('part_type_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['applies_to', 'is_active', 'position'], 'option_templates_scope_active_position_idx');
        });

        Schema::create('product_option_template_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_option_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(
                ['product_option_template_id', 'product_option_group_id', 'product_option_value_id'],
                'option_template_group_value_unique',
            );
            $table->index(
                ['product_option_template_id', 'product_option_group_id'],
                'option_template_group_idx',
            );
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_option_template_id')
                ->nullable()
                ->after('part_type_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::create('product_variant_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_option_value_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['product_variant_id', 'product_option_group_id'],
                'variant_option_group_unique',
            );
            $table->index('product_option_value_id', 'variant_option_value_idx');
        });

        Schema::create('product_characteristics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('value');
            $table->string('unit')->nullable();
            $table->string('source_type')->default('manual')->index();
            $table->boolean('is_visible')->default(true)->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position'], 'characteristics_product_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_characteristics');
        Schema::dropIfExists('product_variant_option_values');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_option_template_id');
        });

        Schema::dropIfExists('product_option_template_items');
        Schema::dropIfExists('product_option_templates');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_option_groups');
    }
};
