<?php

declare(strict_types=1);

$common = require __DIR__ . '/common.php';
$controllerMap = [
    'dev' => App\Console\DevController::class,
    'bitrix' => App\Console\BitrixController::class,
    'notification' => App\Console\NotificationController::class,
    'migrate' => [
        'class' => yii\console\controllers\MigrateController::class,
        'migrationPath' => '@app/migrations',
        'interactive' => false,
    ],
];
if (YII_ENV === 'test') {
    $controllerMap['test'] = App\Console\TestController::class;
}

return yii\helpers\ArrayHelper::merge($common, [
    'controllerNamespace' => 'yii\\console\\controllers',
    'controllerMap' => $controllerMap,
]);
