<?php

test('test suite uses the isolated testing environment', function () {
    expect(app()->runningUnitTests())->toBeTrue()
        ->and(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('session.driver'))->toBe('array');
});
