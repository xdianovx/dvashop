<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faq_category_id')->constrained()->restrictOnDelete();
            $table->string('code', 96)->unique();
            $table->string('question', 500);
            $table->text('answer');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['faq_category_id', 'is_active', 'position']);
            $table->index(['is_featured', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
    }
};
