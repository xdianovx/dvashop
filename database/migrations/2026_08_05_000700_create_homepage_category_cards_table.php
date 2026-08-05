<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_category_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('title');
            $table->string('link_type')->nullable();
            $table->string('route_name')->nullable();
            $table->string('url', 2048)->nullable();
            $table->boolean('open_in_new_tab')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_category_cards');
    }
};
