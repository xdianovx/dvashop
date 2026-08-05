<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_page_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('static_page_id')->constrained()->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('label')->nullable();
            $table->string('title')->nullable();
            $table->text('subtitle')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['static_page_id', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_page_sections');
    }
};
