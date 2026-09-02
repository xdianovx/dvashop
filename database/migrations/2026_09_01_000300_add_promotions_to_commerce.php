<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->nullable()->after('user_id')->constrained('promo_codes')->nullOnDelete();
            $table->timestamp('promo_code_applied_at')->nullable()->after('promo_code_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->nullable()->after('cart_id')->constrained('promo_codes')->nullOnDelete();
            $table->string('promo_code_snapshot', 64)->nullable()->after('promo_code_id');
            $table->string('promo_name_snapshot')->nullable()->after('promo_code_snapshot');
            $table->string('promo_discount_type_snapshot', 32)->nullable()->after('promo_name_snapshot');
            $table->decimal('promo_discount_value_snapshot', 12, 4)->nullable()->after('promo_discount_type_snapshot');
            $table->decimal('discount_total', 12, 2)->default(0)->after('subtotal');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('discount_snapshot', 12, 2)->default(0)->after('total_snapshot');
            $table->decimal('final_total_snapshot', 12, 2)->default(0)->after('discount_snapshot');
        });

        DB::table('order_items')->update([
            'discount_snapshot' => 0,
            'final_total_snapshot' => DB::raw('total_snapshot'),
        ]);

        Schema::create('promo_code_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['promo_code_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['discount_snapshot', 'final_total_snapshot']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['promo_code_id']);
            $table->dropColumn([
                'promo_code_id',
                'promo_code_snapshot',
                'promo_name_snapshot',
                'promo_discount_type_snapshot',
                'promo_discount_value_snapshot',
                'discount_total',
            ]);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign(['promo_code_id']);
            $table->dropColumn(['promo_code_id', 'promo_code_applied_at']);
        });
    }
};
