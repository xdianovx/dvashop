<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homepage_category_cards', function (Blueprint $table): void {
            $table->foreignId('product_category_id')
                ->nullable()
                ->after('route_name');
            $table->foreignId('part_type_id')
                ->nullable()
                ->after('product_category_id');

            $table->index('product_category_id', 'hcc_product_category_idx');
            $table->index('part_type_id', 'hcc_part_type_idx');
            $table->foreign('product_category_id', 'hcc_product_category_fk')
                ->references('id')
                ->on('product_categories')
                ->restrictOnDelete();
            $table->foreign('part_type_id', 'hcc_part_type_fk')
                ->references('id')
                ->on('part_types')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('homepage_category_cards', function (Blueprint $table) use ($driver): void {
            if ($driver === 'sqlite') {
                $table->dropForeign(['product_category_id']);
                $table->dropForeign(['part_type_id']);
            } else {
                $table->dropForeign('hcc_product_category_fk');
                $table->dropForeign('hcc_part_type_fk');
            }

            $table->dropIndex('hcc_product_category_idx');
            $table->dropIndex('hcc_part_type_idx');
            $table->dropColumn(['product_category_id', 'part_type_id']);
        });
    }
};
