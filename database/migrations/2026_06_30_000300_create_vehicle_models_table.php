<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_make_id')->constrained('vehicle_makes')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->string('norm_key');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_make_id', 'position']);
            $table->unique(['vehicle_make_id', 'slug']);
            $table->unique(['vehicle_make_id', 'norm_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
