<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->string('max_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn('max_url');
        });
    }
};
