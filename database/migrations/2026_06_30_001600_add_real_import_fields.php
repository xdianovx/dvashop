<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'import_source')) {
                $table->string('import_source')->nullable()->after('import_key')->index();
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['import_source', 'status', 'last_import_run_id'], 'products_import_source_status_run_idx');
        });

        Schema::table('vehicle_generations', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehicle_generations', 'image_source_url')) {
                $table->text('image_source_url')->nullable()->after('image');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_import_source_status_run_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'import_source')) {
                $table->dropColumn('import_source');
            }
        });

        Schema::table('vehicle_generations', function (Blueprint $table): void {
            if (Schema::hasColumn('vehicle_generations', 'image_source_url')) {
                $table->dropColumn('image_source_url');
            }
        });
    }
};
