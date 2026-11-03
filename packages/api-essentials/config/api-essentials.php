<?php

declare(strict_types=1);

return [
    /*
     |--------------------------------------------------------------------------
     | Force HTTPS
     |--------------------------------------------------------------------------
     |
     | When enabled, all HTTP requests to API routes will be rejected
     | with a 400 Bad Request if they are not using HTTPS.
     |
     */
    'force_https' => (bool) env('SECURITY_FORCE_HTTPS', false),

    /*
     |--------------------------------------------------------------------------
     | HTTP Strict Transport Security (HSTS)
     |--------------------------------------------------------------------------
     |
     | HSTS informs browsers that the site should only be accessed using HTTPS.
     |
     */
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', false),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],
];
