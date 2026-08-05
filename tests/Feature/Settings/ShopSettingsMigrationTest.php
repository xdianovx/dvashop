<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('shop settings migration upgrades rolls back and upgrades without changing existing data', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'shop_settings_upgrade';

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('existing_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('existing_records')->insert(['id' => 1, 'value' => 'Сохранить']);

        $migration = require database_path('migrations/2026_08_05_000100_create_shop_settings_table.php');
        $migration->up();

        expect(Schema::hasColumns('shop_settings', [
            'id',
            'singleton_key',
            'store_name',
            'phone_display',
            'phone_href',
            'phone_caption',
            'public_email',
            'order_notification_email',
            'work_hours',
            'legal_name',
            'inn',
            'ogrn',
            'legal_address',
            'vk_url',
            'telegram_url',
            'footer_copyright',
            'footer_disclaimer',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

        DB::table('shop_settings')->insert([
            'singleton_key' => 'default',
            'store_name' => 'Тестовый магазин',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        expect(Schema::hasTable('shop_settings'))->toBeFalse()
            ->and(DB::table('existing_records')->where('id', 1)->value('value'))->toBe('Сохранить');

        $migration->up();

        expect(Schema::hasTable('shop_settings'))->toBeTrue()
            ->and(DB::table('shop_settings')->count())->toBe(0)
            ->and(DB::table('existing_records')->where('id', 1)->value('value'))->toBe('Сохранить');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
