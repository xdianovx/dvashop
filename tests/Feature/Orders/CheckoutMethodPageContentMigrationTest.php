<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('checkout method page content migration upgrades rolls back and preserves existing method data', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'checkout_method_page_content_upgrade';

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
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('unrelated_records')->insert(['id' => 91, 'value' => 'keep']);

        $deliveryMigration = require database_path('migrations/2026_08_05_000300_create_delivery_method_settings_table.php');
        $paymentMigration = require database_path('migrations/2026_08_05_000400_create_payment_method_settings_table.php');
        $pageContentMigration = require database_path('migrations/2026_08_07_000300_add_page_content_to_checkout_method_settings_tables.php');

        $deliveryMigration->up();
        $paymentMigration->up();

        DB::table('delivery_method_settings')->insert([
            'code' => 'transport_company',
            'title' => 'Существующая доставка',
            'description' => 'Существующее описание доставки',
            'base_price' => 725.50,
            'is_active' => false,
            'position' => 73,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_method_settings')->insert([
            'code' => 'invoice',
            'title' => 'Существующая оплата',
            'description' => 'Существующее описание оплаты',
            'is_active' => false,
            'position' => 74,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pageContentMigration->up();

        expect(Schema::hasColumns('delivery_method_settings', ['page_title', 'page_description']))->toBeTrue()
            ->and(Schema::hasColumns('payment_method_settings', ['page_title', 'page_description']))->toBeTrue()
            ->and(DB::table('delivery_method_settings')->where('code', 'transport_company')->first())->toMatchObject([
                'title' => 'Существующая доставка',
                'description' => 'Существующее описание доставки',
                'base_price' => 725.50,
                'is_active' => 0,
                'position' => 73,
                'page_title' => null,
                'page_description' => null,
            ])->and(DB::table('payment_method_settings')->where('code', 'invoice')->first())->toMatchObject([
                'title' => 'Существующая оплата',
                'description' => 'Существующее описание оплаты',
                'is_active' => 0,
                'position' => 74,
                'page_title' => null,
                'page_description' => null,
            ]);

        DB::table('delivery_method_settings')->where('code', 'transport_company')->update([
            'page_title' => 'Страница доставки',
            'page_description' => 'Описание страницы доставки',
        ]);
        DB::table('payment_method_settings')->where('code', 'invoice')->update([
            'page_title' => 'Страница оплаты',
            'page_description' => 'Описание страницы оплаты',
        ]);

        $pageContentMigration->down();

        expect(Schema::hasColumn('delivery_method_settings', 'page_title'))->toBeFalse()
            ->and(Schema::hasColumn('delivery_method_settings', 'page_description'))->toBeFalse()
            ->and(Schema::hasColumn('payment_method_settings', 'page_title'))->toBeFalse()
            ->and(Schema::hasColumn('payment_method_settings', 'page_description'))->toBeFalse()
            ->and(DB::table('delivery_method_settings')->where('code', 'transport_company')->first())->toMatchObject([
                'title' => 'Существующая доставка',
                'description' => 'Существующее описание доставки',
                'base_price' => 725.50,
                'is_active' => 0,
                'position' => 73,
            ])->and(DB::table('payment_method_settings')->where('code', 'invoice')->first())->toMatchObject([
                'title' => 'Существующая оплата',
                'description' => 'Существующее описание оплаты',
                'is_active' => 0,
                'position' => 74,
            ])->and(DB::table('unrelated_records')->where('id', 91)->value('value'))->toBe('keep');

        $pageContentMigration->up();

        expect(Schema::hasColumns('delivery_method_settings', ['page_title', 'page_description']))->toBeTrue()
            ->and(Schema::hasColumns('payment_method_settings', ['page_title', 'page_description']))->toBeTrue()
            ->and(DB::table('delivery_method_settings')->where('code', 'transport_company')->value('title'))->toBe('Существующая доставка')
            ->and(DB::table('payment_method_settings')->where('code', 'invoice')->value('title'))->toBe('Существующая оплата')
            ->and(DB::table('unrelated_records')->where('id', 91)->value('value'))->toBe('keep');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
