<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->json('configuration_review')->nullable();
            $table->char('configuration_confirmed_hash', 64)->nullable();
            $table->foreignId('configuration_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('configuration_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('configuration_confirmed_by');
            $table->dropColumn(['configuration_review', 'configuration_confirmed_hash', 'configuration_confirmed_at']);
        });
    }
};
