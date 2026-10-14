<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_option_templates')
            || ! Schema::hasTable('product_option_groups')
            || ! Schema::hasTable('product_option_values')
            || ! Schema::hasTable('product_option_template_items')
            || ! Schema::hasTable('product_variant_option_values')) {
            return;
        }

        DB::transaction(function (): void {
            $template = DB::table('product_option_templates')
                ->where('slug', 'default_auto_part')
                ->first(['id']);

            if ($template === null) {
                return;
            }

            $profileGroup = DB::table('product_option_groups')
                ->where('code', 'profile')
                ->first(['id']);

            $profileGroup ??= DB::table('product_option_groups')
                ->where('slug', 'profile')
                ->first(['id']);

            if ($profileGroup === null) {
                return;
            }

            $legacy = DB::table('product_option_values')
                ->where('product_option_group_id', $profileGroup->id)
                ->where('code', 'lncreased')
                ->first(['id']);

            $legacy ??= DB::table('product_option_values')
                ->where('product_option_group_id', $profileGroup->id)
                ->where('slug', 'lncreased')
                ->first(['id']);

            if ($legacy === null) {
                return;
            }

            $legacyMembershipExists = DB::table('product_option_template_items')
                ->where('product_option_template_id', $template->id)
                ->where('product_option_group_id', $profileGroup->id)
                ->where('product_option_value_id', $legacy->id)
                ->exists();

            if (! $legacyMembershipExists) {
                return;
            }

            if (DB::table('product_variant_option_values')
                ->where('product_option_value_id', $legacy->id)
                ->exists()) {
                throw new RuntimeException(
                    'Нельзя нормализовать default_auto_part: legacy profile/lncreased используется вариантами товаров. '
                    .'Миграция остановлена без изменения membership.',
                );
            }

            DB::table('product_option_template_items')
                ->where('product_option_template_id', $template->id)
                ->where('product_option_group_id', $profileGroup->id)
                ->where('product_option_value_id', $legacy->id)
                ->delete();
        });
    }

    public function down(): void
    {
        // Intentional no-op. Re-adding a known-invalid legacy membership would
        // make the system template incompatible with the current import contract.
    }
};
