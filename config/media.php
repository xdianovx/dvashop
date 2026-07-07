<?php

return [
    'max_source_size' => env('MEDIA_MAX_SOURCE_SIZE', 15 * 1024 * 1024),

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    'profiles' => [
        'product_gallery' => [
            'max_width' => 1600,
            'max_height' => 1600,
            'quality' => 82,
            'conversions' => [
                'thumb' => ['width' => 300, 'height' => 300, 'quality' => 78],
                'card' => ['width' => 600, 'height' => 600, 'quality' => 80],
            ],
        ],

        'vehicle_image' => [
            'max_width' => 1200,
            'max_height' => 900,
            'quality' => 82,
            'conversions' => [
                'thumb' => ['width' => 300, 'height' => 220, 'quality' => 78],
            ],
        ],

        'brand_image' => [
            'max_width' => 600,
            'max_height' => 600,
            'quality' => 85,
            'conversions' => [],
        ],
    ],
];
