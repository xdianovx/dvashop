<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_option_template_id')->constrained()->restrictOnDelete();
            $table->string('signature', 64);
            $table->json('options');
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['part_type_id', 'signature']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('configuration_managed')->default(false);
            $table->boolean('import_present')->nullable();
            $table->string('publication_override', 20)->nullable();
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->foreignId('catalog_configuration_id')->nullable()->constrained()->restrictOnDelete();
            // True only when the configuration synchronizer disabled an otherwise active variant.
            // A manually disabled variant must not be revived when a CSV row returns.
            $table->boolean('configuration_auto_retired')->default(false);
            $table->unique(['product_id', 'catalog_configuration_id'], 'variant_configuration_unique');
        });
        Schema::create('catalog_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('status', 20)->default('pending')->index();
            $table->json('parameters');
            $table->json('report');
            $table->unsignedBigInteger('last_id')->default(0);
            $table->unsignedBigInteger('publication_cursor')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_action_items', function (Blueprint $table): void {
            $table->foreignId('catalog_action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->json('before')->nullable();
            $table->json('result')->nullable();
            $table->primary(['catalog_action_id', 'product_id']);
        });
        Schema::table('catalog_price_locks', fn (Blueprint $table) => $table->foreignId('catalog_action_id')->nullable()->constrained()->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('catalog_price_locks', fn (Blueprint $table) => $table->dropConstrainedForeignId('catalog_action_id'));
        Schema::dropIfExists('catalog_action_items');
        Schema::dropIfExists('catalog_actions');
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('variant_configuration_unique');
            $table->dropConstrainedForeignId('catalog_configuration_id');
            $table->dropColumn('configuration_auto_retired');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['configuration_managed', 'import_present', 'publication_override']));
        Schema::dropIfExists('catalog_configurations');
    }
};
