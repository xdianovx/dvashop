<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('cart_id')->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->change();
            $table->string('sku_snapshot')->nullable()->after('product_variant_id');
            $table->json('options_snapshot')->nullable()->after('title_snapshot');
            $table->string('image_snapshot', 2048)->nullable()->after('options_snapshot');
            $table->decimal('old_price_snapshot', 12, 2)->nullable()->after('price_snapshot');
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_status')->default('pending')->after('status')->index();
            $table->string('payment_method')->nullable()->after('payment_status')->index();
            $table->string('delivery_method')->nullable()->after('payment_method')->index();
            $table->string('customer_city')->nullable()->after('customer_email');
            $table->string('customer_address')->nullable()->after('customer_city');
            $table->text('customer_comment')->nullable()->after('customer_address');
            $table->text('manager_comment')->nullable()->after('comment');
            $table->decimal('delivery_price', 12, 2)->default(0)->after('subtotal');
            $table->timestamp('placed_at')->nullable()->after('total')->index();
            $table->timestamp('paid_at')->nullable()->after('placed_at')->index();
        });

        DB::table('orders')->update([
            'customer_city' => DB::raw('delivery_city'),
            'customer_address' => DB::raw('delivery_address'),
            'customer_comment' => DB::raw('comment'),
            'placed_at' => DB::raw('created_at'),
        ]);

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->change();
            $table->foreignId('product_variant_id')->nullable()->change();
            $table->string('sku_snapshot')->nullable()->after('product_variant_id');
            $table->string('title_snapshot')->nullable()->after('sku_snapshot');
            $table->json('options_snapshot')->nullable()->after('title_snapshot');
            $table->string('image_snapshot', 2048)->nullable()->after('options_snapshot');
            $table->decimal('price_snapshot', 12, 2)->nullable()->after('image_snapshot');
            $table->decimal('old_price_snapshot', 12, 2)->nullable()->after('price_snapshot');
            $table->decimal('total_snapshot', 12, 2)->nullable()->after('quantity');
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
        });

        DB::table('order_items')->update([
            'sku_snapshot' => DB::raw('sku'),
            'title_snapshot' => DB::raw('title'),
            'price_snapshot' => DB::raw('price'),
            'total_snapshot' => DB::raw('total'),
        ]);

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('title_snapshot')->nullable(false)->change();
            $table->decimal('price_snapshot', 12, 2)->nullable(false)->change();
            $table->decimal('total_snapshot', 12, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn([
                'sku_snapshot',
                'title_snapshot',
                'options_snapshot',
                'image_snapshot',
                'price_snapshot',
                'old_price_snapshot',
                'total_snapshot',
            ]);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable(false)->change();
            $table->foreignId('product_variant_id')->nullable(false)->change();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['payment_method']);
            $table->dropIndex(['delivery_method']);
            $table->dropIndex(['placed_at']);
            $table->dropIndex(['paid_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_status',
                'payment_method',
                'delivery_method',
                'customer_city',
                'customer_address',
                'customer_comment',
                'manager_comment',
                'delivery_price',
                'placed_at',
                'paid_at',
            ]);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropColumn([
                'product_id',
                'sku_snapshot',
                'options_snapshot',
                'image_snapshot',
                'old_price_snapshot',
            ]);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable(false)->change();
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });
    }
};
