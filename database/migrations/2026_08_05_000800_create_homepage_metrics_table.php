<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('prefix', 32)->nullable();
            $table->string('value', 64);
            $table->string('suffix', 64)->nullable();
            $table->string('text', 500);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_metrics');
    }
};
