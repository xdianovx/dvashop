<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('inquiry notification email is added by a reversible forward migration', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'shop_settings_inquiry_email_upgrade';

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
        Schema::create('shop_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->string('order_notification_email')->nullable();
            $table->timestamps();
        });
        DB::table('shop_settings')->insert([
            'singleton_key' => 'default',
            'order_notification_email' => 'orders@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_09_000200_add_inquiry_notification_email_to_shop_settings_table.php');
        $migration->up();

        expect(Schema::hasColumn('shop_settings', 'inquiry_notification_email'))->toBeTrue()
            ->and(DB::table('shop_settings')->where('singleton_key', 'default')->value('order_notification_email'))
            ->toBe('orders@example.test');

        DB::table('shop_settings')->where('singleton_key', 'default')->update([
            'inquiry_notification_email' => 'inquiries@example.test',
        ]);
        $migration->down();

        expect(Schema::hasColumn('shop_settings', 'inquiry_notification_email'))->toBeFalse()
            ->and(DB::table('shop_settings')->where('singleton_key', 'default')->value('order_notification_email'))
            ->toBe('orders@example.test');

        $migration->up();

        expect(Schema::hasColumn('shop_settings', 'inquiry_notification_email'))->toBeTrue()
            ->and(DB::table('shop_settings')->where('singleton_key', 'default')->value('inquiry_notification_email'))
            ->toBeNull();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
