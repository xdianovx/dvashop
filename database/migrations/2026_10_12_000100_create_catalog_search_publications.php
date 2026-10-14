<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_search_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('model_position')->default(0);
            $table->unsignedBigInteger('last_id')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_attempt_at');
            $table->index(['completed_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_search_publications');
    }
};
