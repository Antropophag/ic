<?php

declare(strict_types=1);

return [
    'params' => ['identityHeader' => 'X-Dev-User-ID'],
    'components' => [
        'db' => [
            'commandClass' => yii\db\Command::class,
        ],
        'urlManager' => [
            'rules' => [
                'GET health/logging' => 'health/logging',
                'GET api/v1/dev/users' => 'dev/users',
                'GET api/v1/dev/review-feedback' => 'dev/review-feedback',
                'POST api/v1/dev/review-feedback' => 'dev/create-review-feedback',
                'POST api/v1/dev/seed-requests' => 'dev/seed-requests',
            ],
        ],
    ],
];
