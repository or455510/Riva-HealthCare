<?php

// SECURITY (Phase 0): the previous 'allowed_origins' => ['*'] let any website on the
// internet make authenticated cross-origin requests against this API using a user's
// bearer token (e.g. a token leaked via XSS on another site, or pasted into a
// malicious tool). CORS is now driven by an explicit, comma-separated allowlist read
// from CORS_ALLOWED_ORIGINS, falling back to FRONTEND_URL so local/dev setups keep
// working without extra configuration. Add CORS_ALLOWED_ORIGINS to your .env for
// any deployment that serves more than one frontend origin (e.g. web + a staging URL).

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://127.0.0.1:4200')))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
