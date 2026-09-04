<?php

declare(strict_types=1);

test('test suite uses the isolated testing environment', function () {
    expect(app()->runningUnitTests())->toBeTrue()
        ->and(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('shop.orders.bitrix_enabled'))->toBeFalse()
        ->and(config('shop.inquiries.bitrix_enabled'))->toBeFalse()
        ->and(config('shop.bitrix.webhook_url'))->toBe('')
        ->and(config('shop.orders.customer_email_enabled'))->toBeFalse()
        ->and(config('shop.orders.manager_email_enabled'))->toBeFalse()
        ->and(config('shop.inquiries.email_enabled'))->toBeFalse();
});
