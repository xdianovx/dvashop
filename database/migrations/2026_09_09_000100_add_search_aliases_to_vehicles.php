<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vehicle_makes', 'vehicle_models'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json('search_aliases')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['vehicle_makes', 'vehicle_models'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('search_aliases'));
        }
    }
};
