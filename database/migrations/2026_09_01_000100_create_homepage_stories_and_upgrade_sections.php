<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_story_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('cover_image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('homepage_story_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('homepage_story_group_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 16);
            $table->string('media_path');
            $table->string('alt_text')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url', 2048)->nullable();
            $table->boolean('open_in_new_tab')->default(false);
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['homepage_story_group_id', 'position']);
            $table->index(['homepage_story_group_id', 'is_active', 'position'], 'homepage_story_items_group_active_position_index');
        });

        $now = now();
        $quickLinks = DB::table('homepage_sections')->where('code', 'quick_links')->first();
        $stories = DB::table('homepage_sections')->where('code', 'stories')->first();

        if ($quickLinks !== null && $stories === null) {
            DB::table('homepage_sections')->where('id', $quickLinks->id)->update([
                'code' => 'stories',
                'title' => $quickLinks->title === 'СЕКЦИЯ' ? null : $quickLinks->title,
                'position' => 10,
                'updated_at' => $now,
            ]);
        } elseif ($quickLinks === null && $stories === null) {
            DB::table('homepage_sections')->insert([
                'code' => 'stories',
                'title' => null,
                'is_active' => true,
                'position' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('homepage_sections')->where('code', 'vehicle_search')->update(['position' => 20, 'updated_at' => $now]);
        DB::table('homepage_sections')->where('code', 'category_cards')->update([
            'title' => DB::raw("CASE WHEN title = 'СЕКЦИЯ 1' THEN NULL ELSE title END"),
            'position' => 30,
            'updated_at' => $now,
        ]);

        if (! DB::table('homepage_sections')->where('code', 'reviews')->exists()) {
            DB::table('homepage_sections')->insert([
                'code' => 'reviews',
                'title' => 'Отзывы клиентов',
                'is_active' => true,
                'position' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('homepage_sections')->where('code', 'reviews')->update(['position' => 40, 'updated_at' => $now]);
        }

        DB::table('homepage_sections')->where('code', 'about_metrics')->update(['position' => 50, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $now = now();

        DB::table('homepage_sections')->where('code', 'reviews')->delete();
        if (! DB::table('homepage_sections')->where('code', 'quick_links')->exists()) {
            DB::table('homepage_sections')->where('code', 'stories')->update([
                'code' => 'quick_links',
                'position' => 10,
                'updated_at' => $now,
            ]);
        }

        DB::table('homepage_sections')->where('code', 'vehicle_search')->update(['position' => 20, 'updated_at' => $now]);
        DB::table('homepage_sections')->where('code', 'category_cards')->update(['position' => 30, 'updated_at' => $now]);
        DB::table('homepage_sections')->where('code', 'about_metrics')->update(['position' => 40, 'updated_at' => $now]);

        Schema::dropIfExists('homepage_story_items');
        Schema::dropIfExists('homepage_story_groups');
    }
};
