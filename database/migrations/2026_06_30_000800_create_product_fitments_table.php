<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_fitments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('vehicle_generation_id')->constrained('vehicle_generations')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();

            $table->unique(['product_id', 'vehicle_generation_id']);
            $table->index(['vehicle_generation_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_fitments');
    }
};
