<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('created_makes')->default(0)->after('detail_columns');
            $table->unsignedInteger('updated_makes')->default(0)->after('created_makes');
            $table->unsignedInteger('created_models')->default(0)->after('updated_makes');
            $table->unsignedInteger('updated_models')->default(0)->after('created_models');
            $table->unsignedInteger('created_generations')->default(0)->after('updated_models');
            $table->unsignedInteger('updated_generations')->default(0)->after('created_generations');
            $table->unsignedInteger('created_categories')->default(0)->after('updated_generations');
            $table->unsignedInteger('updated_categories')->default(0)->after('created_categories');
            $table->unsignedInteger('created_products')->default(0)->after('updated_categories');
            $table->unsignedInteger('updated_products')->default(0)->after('created_products');
            $table->unsignedInteger('archived_products')->default(0)->after('updated_products');
            $table->unsignedInteger('queued_images')->default(0)->after('archived_products');
            $table->unsignedInteger('processed_images')->default(0)->after('queued_images');
            $table->unsignedInteger('failed_images')->default(0)->after('processed_images');
            $table->unsignedInteger('warnings_count')->default(0)->after('failed_images');
            $table->unsignedInteger('errors_count')->default(0)->after('warnings_count');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'created_makes',
                'updated_makes',
                'created_models',
                'updated_models',
                'created_generations',
                'updated_generations',
                'created_categories',
                'updated_categories',
                'created_products',
                'updated_products',
                'archived_products',
                'queued_images',
                'processed_images',
                'failed_images',
                'warnings_count',
                'errors_count',
            ]);
        });
    }
};
