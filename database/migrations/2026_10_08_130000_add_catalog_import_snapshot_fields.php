<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->json('prepared_rows')->nullable();
        });
        Schema::table('vehicle_generations', function (Blueprint $table): void {
            $table->string('import_source', 64)->nullable()->index();
            $table->unsignedBigInteger('last_import_run_id')->nullable()->index();
            $table->string('platform')->nullable();
            $table->string('restyling')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', fn (Blueprint $table) => $table->dropColumn('prepared_rows'));
        Schema::table('vehicle_generations', function (Blueprint $table): void {
            $table->dropIndex(['import_source']);
            $table->dropIndex(['last_import_run_id']);
            $table->dropColumn(['import_source', 'last_import_run_id', 'platform', 'restyling']);
        });
    }
};
