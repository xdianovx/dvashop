<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_lists', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('favorite_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('favorite_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['favorite_list_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_items');
        Schema::dropIfExists('favorite_lists');
    }
};
