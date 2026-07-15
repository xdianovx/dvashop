<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('catalog foundation migrations preserve existing products and categories', function () {
    $originalConnection = DB::getDefaultConnection();
    $upgradeConnection = 'catalog_upgrade';

    config([
        "database.connections.{$upgradeConnection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($upgradeConnection);
    DB::setDefaultConnection($upgradeConnection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_category_id')->nullable();
            $table->string('title');
        });

        $categoryId = DB::table('product_categories')->insertGetId(['title' => 'Старая категория']);
        $productId = DB::table('products')->insertGetId([
            'product_category_id' => $categoryId,
            'title' => 'Существующий товар',
        ]);

        (require database_path('migrations/2026_07_15_000100_add_product_type_to_products_table.php'))->up();
        (require database_path('migrations/2026_07_15_000200_create_part_types_table.php'))->up();
        (require database_path('migrations/2026_07_15_000300_add_part_type_id_to_products_table.php'))->up();

        $product = DB::table('products')->where('id', $productId)->first();

        expect($product)->not->toBeNull()
            ->and($product->title)->toBe('Существующий товар')
            ->and($product->product_type)->toBe('auto_part')
            ->and($product->part_type_id)->toBeNull()
            ->and(DB::table('product_categories')->where('id', $categoryId)->value('title'))
            ->toBe('Старая категория');
    } finally {
        DB::disconnect($upgradeConnection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$upgradeConnection}" => null]);
    }
});
