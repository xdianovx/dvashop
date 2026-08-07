<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_method_settings', function (Blueprint $table): void {
            $table->string('page_title')->nullable()->after('description');
            $table->text('page_description')->nullable()->after('page_title');
        });

        Schema::table('delivery_method_settings', function (Blueprint $table): void {
            $table->string('page_title')->nullable()->after('description');
            $table->text('page_description')->nullable()->after('page_title');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_method_settings', function (Blueprint $table): void {
            $table->dropColumn(['page_title', 'page_description']);
        });

        Schema::table('payment_method_settings', function (Blueprint $table): void {
            $table->dropColumn(['page_title', 'page_description']);
        });
    }
};
