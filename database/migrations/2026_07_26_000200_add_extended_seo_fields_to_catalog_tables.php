<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'products',
        'product_categories',
        'part_types',
        'vehicle_makes',
        'vehicle_models',
        'vehicle_generations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('seo_h1')->nullable();
                $table->longText('seo_text')->nullable();
                $table->string('canonical_url', 2048)->nullable();
                $table->boolean('noindex')->default(false)->index();
                $table->string('og_title')->nullable();
                $table->text('og_description')->nullable();
                $table->string('og_image', 2048)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex($table->getTable().'_noindex_index');
                $table->dropColumn([
                    'seo_h1',
                    'seo_text',
                    'canonical_url',
                    'noindex',
                    'og_title',
                    'og_description',
                    'og_image',
                ]);
            });
        }
    }
};
