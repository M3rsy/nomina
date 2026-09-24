<?php

$trustedProxies = env('TRUSTED_PROXIES');

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated proxy IPs/CIDR ranges that are allowed to supply
    | X-Forwarded-* headers. Leave empty for direct/local development so clients
    | cannot spoof scheme, host, port, or client IP values.
    |
    */
    'proxies' => blank($trustedProxies)
        ? null
        : array_values(array_filter(array_map('trim', explode(',', (string) $trustedProxies)))),
];
