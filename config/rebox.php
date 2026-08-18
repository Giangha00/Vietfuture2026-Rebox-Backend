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
        'timeout' => (int) env('REBOX_AI_TIMEOUT', 180),
        // Absolute or relative-to-backend path for versioned JSONL + image exports.
        'dataset_dir' => env('REBOX_AI_DATASET_DIR', dirname(base_path()).'/rebox-ai/data'),
        'rebox_ai_root' => env('REBOX_AI_ROOT', dirname(base_path()).'/rebox-ai'),
        'export_min_approved' => (int) env('REBOX_AI_EXPORT_MIN_APPROVED', 20),
        'export_val_ratio' => (float) env('REBOX_AI_EXPORT_VAL_RATIO', 0.2),
        'auto_min_train' => (int) env('REBOX_AI_AUTO_MIN_TRAIN', 10),
        'auto_min_category' => (float) env('REBOX_AI_AUTO_MIN_CATEGORY', 0.40),
        'auto_min_condition' => (float) env('REBOX_AI_AUTO_MIN_CONDITION', 0.30),
        'auto_eval_limit' => (int) env('REBOX_AI_AUTO_EVAL_LIMIT', 10),
    ],
];
