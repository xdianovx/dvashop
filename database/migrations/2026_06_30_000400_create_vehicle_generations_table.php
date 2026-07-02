<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->string('norm_key');
            $table->string('years_label')->nullable();
            $table->string('body')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_model_id', 'position']);
            $table->unique(['vehicle_model_id', 'slug']);
            $table->unique(['vehicle_model_id', 'norm_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_generations');
    }
};
