<?php

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL));

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

(new yii\web\Application(require dirname(__DIR__) . '/config/web.php'))->run();
