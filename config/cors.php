<?php

return [
    'paths' => ['api/*', 'salesmen', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://tenant_2.app.localhost:3000'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Important for cookies/session
];
