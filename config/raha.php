<?php

return [
    // Dev-only shortcuts (quick sign-in, reset tokens/OTP echoed in responses). Never enable in production.
    'dev_accounts' => env('RAHA_DEV_ACCOUNTS', in_array(env('APP_ENV'), ['local', 'testing'], true)),

    // Frontend origins allowed by CORS (comma separated).
    'frontend_origins' => array_filter(explode(',', (string) env('RAHA_FRONTEND_ORIGINS', 'http://localhost:8080,http://localhost:5173,http://localhost:3000'))),

    'uploads' => [
        'disk' => 'local', // private storage/app/private
        'max_kb' => 5 * 1024,
        'mimes' => ['application/pdf' => 'PDF', 'image/jpeg' => 'JPG', 'image/png' => 'PNG'],
        'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    'otp' => ['ttl_minutes' => 10, 'max_attempts' => 5],
];
