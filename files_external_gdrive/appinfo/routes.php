<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'Oauth#receiveToken',
            'url' => '/oauth',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#saveCredentials',
            'url' => '/settings/credentials',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#getClientId',
            'url' => '/settings/client-id',
            'verb' => 'GET',
        ],
        [
            'name' => 'Oauth#createMount',
            'url' => '/create-mount',
            'verb' => 'POST',
        ],
    ],
];
