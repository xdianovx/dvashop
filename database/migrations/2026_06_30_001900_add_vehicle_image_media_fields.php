<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_makes', function (Blueprint $table): void {
            $table->string('image_checksum', 64)->nullable()->after('image');
            $table->json('image_conversions')->nullable()->after('image_checksum');
            $table->index('image_checksum');
        });

        Schema::table('vehicle_generations', function (Blueprint $table): void {
            $table->string('image_checksum', 64)->nullable()->after('image_source_url');
            $table->json('image_conversions')->nullable()->after('image_checksum');
            $table->index('image_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_generations', function (Blueprint $table): void {
            $table->dropIndex(['image_checksum']);
            $table->dropColumn(['image_checksum', 'image_conversions']);
        });

        Schema::table('vehicle_makes', function (Blueprint $table): void {
            $table->dropIndex(['image_checksum']);
            $table->dropColumn(['image_checksum', 'image_conversions']);
        });
    }
};
