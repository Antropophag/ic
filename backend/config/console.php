<?php

declare(strict_types=1);

$common = require __DIR__ . '/common.php';
$controllerMap = [
    'admin' => App\Console\AdminController::class,
    'bitrix' => App\Console\BitrixController::class,
    'notification' => App\Console\NotificationController::class,
    'migrate' => [
        'class' => yii\console\controllers\MigrateController::class,
        'migrationPath' => '@app/migrations',
        'interactive' => false,
    ],
];
$deploymentFile = '/app/deployment/console.php';
$deployment = is_file($deploymentFile) ? require $deploymentFile : [];

return yii\helpers\ArrayHelper::merge($common, [
    'controllerNamespace' => 'yii\\console\\controllers',
    'controllerMap' => $controllerMap,
], $deployment);
