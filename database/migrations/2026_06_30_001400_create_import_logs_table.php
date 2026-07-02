<?php

use App\Enums\ImportLogLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->string('level')->default(ImportLogLevel::Info->value)->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['import_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
