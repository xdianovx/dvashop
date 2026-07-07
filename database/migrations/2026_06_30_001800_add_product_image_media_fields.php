<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('disk')->default('public')->after('product_variant_id');
            $table->string('original_path')->nullable()->after('path');
            $table->string('source_url', 2048)->nullable()->after('original_path');
            $table->string('mime')->nullable()->after('source_url');
            $table->unsignedInteger('width')->nullable()->after('mime');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedBigInteger('size')->nullable()->after('height');
            $table->string('checksum', 64)->nullable()->after('size');
            $table->json('conversions')->nullable()->after('checksum');

            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropIndex(['checksum']);
            $table->dropColumn([
                'disk',
                'original_path',
                'source_url',
                'mime',
                'width',
                'height',
                'size',
                'checksum',
                'conversions',
            ]);
        });
    }
};
