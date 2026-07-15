<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('part_types')->restrictOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->string('full_slug')->unique();
            $table->string('full_title');
            $table->unsignedInteger('depth')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->string('default_image_key')->nullable()->index();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('product_category_id');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_types');
    }
};
