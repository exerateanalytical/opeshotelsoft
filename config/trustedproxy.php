<?php

return [
    /*
    | Proxies whose X-Forwarded-* headers are trusted (read by Laravel's TrustProxies middleware).
    | TRUSTED_PROXIES: comma-separated addresses or CIDR ranges, e.g. "127.0.0.1" or "172.16.0.0/12".
    | Default: none, so X-Forwarded-For is ignored and every IP-keyed throttle sees the real peer address.
    | "*" trusts the calling peer (only safe when the app is reachable through the proxy alone). See docs/DEPLOY.md.
    */
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
