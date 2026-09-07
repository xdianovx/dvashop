<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('YANDEX_FEED_ENABLED', false),
    'directory' => storage_path('app/feeds'),
    // Always asynchronous, including installations whose default queue is sync.
    'connection' => env('YANDEX_FEED_QUEUE_CONNECTION', 'yandex-feed'),
    'queue' => env('YANDEX_FEED_QUEUE', 'yandex-feed'),
    'debounce_seconds' => 30,
    'dispatch_lease_seconds' => 900,
    'chunk_size' => 200,
];
