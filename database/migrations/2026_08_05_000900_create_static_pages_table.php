<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('title');
            $table->text('subtitle')->nullable();
            $table->string('primary_action_label')->nullable();
            $table->string('secondary_action_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_pages');
    }
};
