<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('delivery_method_title_snapshot')->nullable()->after('delivery_method');
            $table->text('delivery_method_description_snapshot')->nullable()->after('delivery_method_title_snapshot');
            $table->string('payment_method_title_snapshot')->nullable()->after('payment_method');
            $table->text('payment_method_description_snapshot')->nullable()->after('payment_method_title_snapshot');
            $table->timestamp('customer_email_sent_at')->nullable()->after('paid_at');
            $table->timestamp('manager_email_sent_at')->nullable()->after('customer_email_sent_at');
            $table->timestamp('bitrix_sent_at')->nullable()->after('manager_email_sent_at');
            $table->string('bitrix_entity_id')->nullable()->after('bitrix_sent_at');
        });

        foreach (DeliveryMethod::cases() as $method) {
            DB::table('orders')
                ->where('delivery_method', $method->value)
                ->whereNull('delivery_method_title_snapshot')
                ->update(['delivery_method_title_snapshot' => $method->label()]);
        }

        foreach (PaymentMethod::cases() as $method) {
            DB::table('orders')
                ->where('payment_method', $method->value)
                ->whereNull('payment_method_title_snapshot')
                ->update(['payment_method_title_snapshot' => $method->label()]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_method_title_snapshot',
                'delivery_method_description_snapshot',
                'payment_method_title_snapshot',
                'payment_method_description_snapshot',
                'customer_email_sent_at',
                'manager_email_sent_at',
                'bitrix_sent_at',
                'bitrix_entity_id',
            ]);
        });
    }
};
