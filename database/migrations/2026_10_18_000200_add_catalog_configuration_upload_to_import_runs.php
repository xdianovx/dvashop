<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->string('import_mode', 24)->default('excel')->index();
            $table->string('configuration_path')->nullable();
            $table->char('configuration_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn(['import_mode', 'configuration_path', 'configuration_hash']);
        });
    }
};
