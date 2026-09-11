<?php

return [
    'driver' => env('CATALOG_SEARCH_DRIVER', 'database'),
    'timeout_ms' => (int) env('CATALOG_SEARCH_TIMEOUT_MS', 250),
];
