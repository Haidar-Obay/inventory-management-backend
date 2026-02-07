<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'], // add * if you have web routes too
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        '*',
        'http://hadishokor.app.localhost:3000',
        'http://hadishokor.app.localhost:8000',
        'http://*.localhost:3000',
        'http://*.app.localhost:8000',
        'http://app.localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:3000',
    ],
    'allowed_origins_patterns' => [
        '#^http://[a-z0-9-]+\.localhost:3000$#',
        '#^http://[a-z0-9-]+\.app\.localhost:8000$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // important for cookies
];