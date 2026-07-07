<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_images', 'source_type')) {
                $table->string('source_type', 32)->nullable()->after('source_url')->index();
            }

            if (! Schema::hasColumn('product_images', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('position')->index();
            }

            if (! Schema::hasColumn('product_images', 'is_visible')) {
                $table->boolean('is_visible')->default(true)->after('is_main')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            if (Schema::hasColumn('product_images', 'is_visible')) {
                $table->dropColumn('is_visible');
            }

            if (Schema::hasColumn('product_images', 'is_default')) {
                $table->dropColumn('is_default');
            }

            if (Schema::hasColumn('product_images', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
