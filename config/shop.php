<?php

return [
    'orders_manager_email' => env('SHOP_ORDERS_MANAGER_EMAIL'),

    'inquiries' => [
        'email_enabled' => (bool) env('SHOP_INQUIRIES_EMAIL_ENABLED', true),
        'bitrix_enabled' => (bool) env('SHOP_INQUIRIES_BITRIX_ENABLED', false),
        'manager_email' => env('SHOP_INQUIRIES_MANAGER_EMAIL'),
    ],

    'orders' => [
        'customer_email_enabled' => (bool) env('SHOP_ORDERS_CUSTOMER_EMAIL_ENABLED', true),
        'manager_email_enabled' => (bool) env('SHOP_ORDERS_MANAGER_EMAIL_ENABLED', true),
        'bitrix_enabled' => (bool) env('SHOP_ORDERS_BITRIX_ENABLED', false),
    ],

    'bitrix' => [
        'webhook_url' => env('BITRIX_WEBHOOK_URL'),
        'inquiry_method' => env('BITRIX_INQUIRY_METHOD', 'crm.lead.add'),
        'order_method' => env('BITRIX_ORDER_METHOD', 'crm.lead.add'),
    ],
];
