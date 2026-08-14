<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50)->index();
            $table->string('name');
            $table->string('phone', 100);
            $table->string('email')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_title_snapshot')->nullable();
            $table->string('variant_sku_snapshot')->nullable();
            $table->json('options_snapshot')->nullable();
            $table->string('source_url', 2048);
            $table->string('source_code', 100)->index();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('email_failed_at')->nullable();
            $table->timestamp('bitrix_sent_at')->nullable();
            $table->timestamp('bitrix_failed_at')->nullable();
            $table->string('bitrix_entity_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_inquiries');
    }
};
