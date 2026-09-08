<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->string('content_type', 16)->default('page');
            $table->string('pdf_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table): void {
            $table->dropColumn(['content_type', 'pdf_path']);
        });
    }
};
