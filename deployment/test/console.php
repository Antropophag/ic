<?php

declare(strict_types=1);

return [
    'components' => [
        'db' => [
            'commandClass' => yii\db\Command::class,
        ],
    ],
    'controllerMap' => [
        'test' => App\Console\TestController::class,
    ],
];
