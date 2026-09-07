<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('MAX migration preserves existing settings through upgrade rollback and upgrade', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'shop_settings_max_upgrade';
    config(["database.connections.{$connection}" => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        (require database_path('migrations/2026_08_05_000100_create_shop_settings_table.php'))->up();
        DB::table('shop_settings')->insert([
            'singleton_key' => 'default',
            'store_name' => 'Existing store',
            'vk_url' => 'https://vk.example.test/manual',
            'telegram_url' => 'https://telegram.example.test/manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = (array) DB::table('shop_settings')->sole();
        $migration = require database_path('migrations/2026_09_07_000100_add_max_url_to_shop_settings_table.php');
        $migration->up();

        expect(Schema::hasColumn('shop_settings', 'max_url'))->toBeTrue()
            ->and((array) DB::table('shop_settings')->sole())->toBe([...$before, 'max_url' => null]);
        DB::table('shop_settings')->update(['max_url' => 'https://max.example.test/shop']);
        $migration->down();

        expect(Schema::hasColumn('shop_settings', 'max_url'))->toBeFalse()
            ->and((array) DB::table('shop_settings')->sole())->toBe($before);
        $migration->up();
        expect((array) DB::table('shop_settings')->sole())->toBe([...$before, 'max_url' => null]);
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
