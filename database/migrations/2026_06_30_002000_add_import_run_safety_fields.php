<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->timestamp('initialized_at')->nullable()->after('detail_columns')->index();
            $table->boolean('archive_skipped')->default(false)->after('archived_products');
            $table->string('archive_skip_reason')->nullable()->after('archive_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropIndex(['initialized_at']);
            $table->dropColumn(['initialized_at', 'archive_skipped', 'archive_skip_reason']);
        });
    }
};
