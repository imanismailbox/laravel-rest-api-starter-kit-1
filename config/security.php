<?php

declare(strict_types=1);

return [
    /*
     |--------------------------------------------------------------------------
     | Trusted Proxies & Hosts
     |--------------------------------------------------------------------------
     */
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),

    'trusted_hosts' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('TRUSTED_HOSTS', '')),
    ))),
];
