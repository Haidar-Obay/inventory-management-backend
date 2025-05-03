<?php

return [

    'paths' => ['api/*', 'login'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://tenant_1.app.localhost:3000',
        'http://tenant_2.app.localhost:3000',
    ],

    'allowed_origins_patterns' => [
        '/^http:\/\/.+\.app\.localhost:3000$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
