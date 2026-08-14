<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('order notification snapshots and delivery state use a reversible forward migration', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'order_notification_snapshots_upgrade';

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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('payment_method');
            $table->string('delivery_method');
            $table->timestamp('paid_at')->nullable();
        });
        DB::table('orders')->insert([
            'number' => 'DVS-HISTORIC',
            'payment_method' => PaymentMethod::Sbp->value,
            'delivery_method' => DeliveryMethod::TransportCompany->value,
        ]);

        $migration = require database_path('migrations/2026_08_09_000300_add_notification_snapshots_to_orders_table.php');
        $migration->up();

        expect(Schema::hasColumns('orders', [
            'delivery_method_title_snapshot',
            'delivery_method_description_snapshot',
            'payment_method_title_snapshot',
            'payment_method_description_snapshot',
            'customer_email_sent_at',
            'manager_email_sent_at',
            'bitrix_sent_at',
            'bitrix_entity_id',
        ]))->toBeTrue()
            ->and(DB::table('orders')->where('number', 'DVS-HISTORIC')->first())->toMatchObject([
                'delivery_method_title_snapshot' => DeliveryMethod::TransportCompany->label(),
                'delivery_method_description_snapshot' => null,
                'payment_method_title_snapshot' => PaymentMethod::Sbp->label(),
                'payment_method_description_snapshot' => null,
                'customer_email_sent_at' => null,
                'manager_email_sent_at' => null,
                'bitrix_sent_at' => null,
                'bitrix_entity_id' => null,
            ]);

        $migration->down();

        expect(Schema::hasColumn('orders', 'delivery_method_title_snapshot'))->toBeFalse()
            ->and(Schema::hasColumn('orders', 'customer_email_sent_at'))->toBeFalse()
            ->and(DB::table('orders')->where('number', 'DVS-HISTORIC')->value('payment_method'))
            ->toBe(PaymentMethod::Sbp->value);

        $migration->up();

        expect(DB::table('orders')->where('number', 'DVS-HISTORIC')->value('delivery_method_title_snapshot'))
            ->toBe(DeliveryMethod::TransportCompany->label());
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
