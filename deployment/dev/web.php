<?php

declare(strict_types=1);

return [
    'params' => ['identityHeader' => 'X-Dev-User-ID'],
    'components' => [
        'urlManager' => [
            'rules' => [
                'GET health/logging' => 'health/logging',
                'GET api/v1/dev/users' => 'dev/users',
                'POST api/v1/dev/seed-requests' => 'dev/seed-requests',
            ],
        ],
    ],
];
