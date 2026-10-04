<?php

declare(strict_types=1);

return [
    'params' => ['identityHeader' => 'X-Test-User-ID'],
    'components' => [
        'db' => [
            'commandClass' => yii\db\Command::class,
        ],
    ],
];
