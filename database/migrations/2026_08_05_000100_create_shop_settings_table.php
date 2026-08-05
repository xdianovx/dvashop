<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->string('store_name');
            $table->string('phone_display', 100)->nullable();
            $table->string('phone_href', 32)->nullable();
            $table->string('phone_caption')->nullable();
            $table->string('public_email')->nullable();
            $table->string('order_notification_email')->nullable();
            $table->string('work_hours')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('ogrn', 15)->nullable();
            $table->text('legal_address')->nullable();
            $table->string('vk_url')->nullable();
            $table->string('telegram_url')->nullable();
            $table->text('footer_copyright')->nullable();
            $table->string('footer_disclaimer', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
