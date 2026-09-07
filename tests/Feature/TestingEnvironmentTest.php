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
        ->and(config('shop.bitrix.order_product_rows_enabled'))->toBeFalse()
        ->and(config('yandex-feed.enabled'))->toBeFalse()
        ->and(config('yandex-feed.connection'))->toBe('yandex-feed')
        ->and(config('yandex-feed.queue'))->toBe('yandex-feed')
        ->and(config('queue.connections.database.retry_after'))->toBe(660)
        ->and(config('queue.connections.yandex-feed.driver'))->toBe('database')
        ->and(config('queue.connections.yandex-feed.retry_after'))->toBe(1500)
        ->and(file_get_contents(base_path('.env.example')))->toContain('YANDEX_FEED_ENABLED=false')
        ->and(config('shop.orders.customer_email_enabled'))->toBeFalse()
        ->and(config('shop.orders.manager_email_enabled'))->toBeFalse()
        ->and(config('shop.inquiries.email_enabled'))->toBeFalse();
});
