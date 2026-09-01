<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('stories migration upgrades exact placeholders preserves manual titles and rolls back', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'homepage_stories_upgrade';
    config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('homepage_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        foreach ([
            ['code' => 'quick_links', 'title' => 'СЕКЦИЯ'],
            ['code' => 'vehicle_search', 'title' => 'Ручной поиск'],
            ['code' => 'category_cards', 'title' => 'СЕКЦИЯ 1'],
            ['code' => 'about_metrics', 'title' => 'Ручная компания'],
            ['code' => 'unrelated_legacy', 'title' => 'Не связанная секция'],
        ] as $row) {
            DB::table('homepage_sections')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration = require database_path('migrations/2026_09_01_000100_create_homepage_stories_and_upgrade_sections.php');
        $migration->up();

        expect(Schema::hasTable('homepage_story_groups'))->toBeTrue()
            ->and(Schema::hasTable('homepage_story_items'))->toBeTrue()
            ->and(DB::table('homepage_sections')->whereIn('code', ['stories', 'vehicle_search', 'category_cards', 'reviews', 'about_metrics'])->orderBy('position')->pluck('code')->all())->toBe([
                'stories', 'vehicle_search', 'category_cards', 'reviews', 'about_metrics',
            ])
            ->and(DB::table('homepage_sections')->where('code', 'stories')->value('title'))->toBeNull()
            ->and(DB::table('homepage_sections')->where('code', 'category_cards')->value('title'))->toBeNull()
            ->and(DB::table('homepage_sections')->where('code', 'vehicle_search')->value('title'))->toBe('Ручной поиск')
            ->and(DB::table('homepage_sections')->where('code', 'about_metrics')->value('title'))->toBe('Ручная компания');

        $migration->down();
        expect(Schema::hasTable('homepage_story_groups'))->toBeFalse()
            ->and(Schema::hasTable('homepage_story_items'))->toBeFalse()
            ->and(DB::table('homepage_sections')->where('code', 'quick_links')->exists())->toBeTrue();

        $migration->up();

        $groupIndexes = collect(DB::select("PRAGMA index_list('homepage_story_groups')"))->pluck('name')->all();
        $itemIndexes = collect(DB::select("PRAGMA index_list('homepage_story_items')"))->pluck('name')->all();
        $itemForeignKeys = collect(DB::select("PRAGMA foreign_key_list('homepage_story_items')"));

        expect(Schema::hasTable('homepage_story_groups'))->toBeTrue()
            ->and(Schema::hasTable('homepage_story_items'))->toBeTrue()
            ->and($groupIndexes)->toContain('homepage_story_groups_is_active_position_index')
            ->and($itemIndexes)->toContain('homepage_story_items_homepage_story_group_id_position_index')
            ->toContain('homepage_story_items_group_active_position_index')
            ->and($itemForeignKeys->contains(fn (object $foreignKey): bool => $foreignKey->table === 'homepage_story_groups'
                && $foreignKey->from === 'homepage_story_group_id'
                && mb_strtolower((string) $foreignKey->on_delete) === 'cascade'))->toBeTrue()
            ->and(DB::table('homepage_sections')->whereIn('code', ['stories', 'vehicle_search', 'category_cards', 'reviews', 'about_metrics'])->orderBy('position')->pluck('code')->all())->toBe([
                'stories', 'vehicle_search', 'category_cards', 'reviews', 'about_metrics',
            ])
            ->and(DB::table('homepage_sections')->where('code', 'quick_links')->exists())->toBeFalse()
            ->and(DB::table('homepage_sections')->where('code', 'reviews')->exists())->toBeTrue()
            ->and(DB::table('homepage_sections')->where('code', 'stories')->value('title'))->toBeNull()
            ->and(DB::table('homepage_sections')->where('code', 'category_cards')->value('title'))->toBeNull()
            ->and(DB::table('homepage_sections')->where('code', 'vehicle_search')->value('title'))->toBe('Ручной поиск')
            ->and(DB::table('homepage_sections')->where('code', 'about_metrics')->value('title'))->toBe('Ручная компания')
            ->and(DB::table('homepage_sections')->where('code', 'unrelated_legacy')->value('title'))->toBe('Не связанная секция');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
