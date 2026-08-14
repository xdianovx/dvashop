<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_method_settings', function (Blueprint $table): void {
            $table->string('price_mode', 32)->default(DeliveryPriceMode::Fixed->value)->after('base_price');
        });

        DB::table('delivery_method_settings')
            ->where('base_price', '>', 0)
            ->update(['price_mode' => DeliveryPriceMode::Fixed->value]);
        DB::table('delivery_method_settings')
            ->where('base_price', '<=', 0)
            ->where('code', DeliveryMethod::Pickup->value)
            ->update(['price_mode' => DeliveryPriceMode::Free->value]);
        DB::table('delivery_method_settings')
            ->where('base_price', '<=', 0)
            ->where('code', '!=', DeliveryMethod::Pickup->value)
            ->update(['price_mode' => DeliveryPriceMode::OnRequest->value]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('delivery_price_mode_snapshot', 32)->default(DeliveryPriceMode::Fixed->value)->after('delivery_method_description_snapshot');
            $table->boolean('total_is_final')->default(true)->after('total');
        });

        DB::table('orders')
            ->where('delivery_price', '>', 0)
            ->update([
                'delivery_price_mode_snapshot' => DeliveryPriceMode::Fixed->value,
                'total_is_final' => true,
            ]);
        DB::table('orders')
            ->where('delivery_price', '<=', 0)
            ->where('delivery_method', DeliveryMethod::Pickup->value)
            ->update([
                'delivery_price_mode_snapshot' => DeliveryPriceMode::Free->value,
                'total_is_final' => true,
            ]);
        DB::table('orders')
            ->where('delivery_price', '<=', 0)
            ->where('delivery_method', '!=', DeliveryMethod::Pickup->value)
            ->update([
                'delivery_price_mode_snapshot' => DeliveryPriceMode::OnRequest->value,
                'total_is_final' => false,
            ]);

        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('stock_was_decremented')->default(false)->after('quantity');
            $table->timestamp('stock_restored_at')->nullable()->after('stock_was_decremented');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['stock_was_decremented', 'stock_restored_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['delivery_price_mode_snapshot', 'total_is_final']);
        });

        Schema::table('delivery_method_settings', function (Blueprint $table): void {
            $table->dropColumn('price_mode');
        });
    }
};
