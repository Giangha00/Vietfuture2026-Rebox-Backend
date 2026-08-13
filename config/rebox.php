<?php

return [
    'jwt_secret' => env('JWT_SECRET', 'rebox_dev_secret_change_me_to_a_long_random_string_32b+'),
    'jwt_expires_in' => env('JWT_EXPIRES_IN', '7d'),
    'public_base_url' => env('PUBLIC_BASE_URL', 'http://localhost:5001'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'platform_fee_percent' => (float) env('PLATFORM_FEE_PERCENT', 10),
    'buyer_confirm_hours' => (int) env('BUYER_CONFIRM_HOURS', 48),
    'offer_ttl_hours' => (int) env('OFFER_TTL_HOURS', 48),
    'admin_email' => env('ADMIN_EMAIL', 'admin@rebox.com'),
    'admin_password' => env('ADMIN_PASSWORD', 'Admin@123'),
    'admin_name' => env('ADMIN_NAME', 'ReBox Admin'),
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
    ],
    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', ''),
        'client_email' => env('FIREBASE_CLIENT_EMAIL', ''),
        'private_key' => env('FIREBASE_PRIVATE_KEY', ''),
    ],
    'google_email' => env('GOOGLE_EMAIL', ''),
    'google_app_password' => preg_replace('/\s+/', '', (string) env('GOOGLE_APP_PASSWORD', '')),
    'ai' => [
        'url' => env('REBOX_AI_URL', 'http://127.0.0.1:8100'),
        'timeout' => (int) env('REBOX_AI_TIMEOUT', 120),
    ],
    'upload' => [
        'max_files' => 8,
        'max_bytes' => (int) (1.5 * 1024 * 1024),
    ],
];
