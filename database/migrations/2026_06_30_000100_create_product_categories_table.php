<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->string('full_slug')->unique();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index(['parent_id', 'position']);
            $table->index(['parent_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
