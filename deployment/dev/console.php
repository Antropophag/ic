<?php

declare(strict_types=1);

return [
    'components' => [
        'db' => [
            'commandClass' => yii\db\Command::class,
        ],
    ],
    'controllerMap' => [
        'dev' => App\Console\DevController::class,
    ],
];
