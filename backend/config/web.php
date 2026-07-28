<?php

declare(strict_types=1);

$common = require __DIR__ . '/common.php';

return yii\helpers\ArrayHelper::merge($common, [
    'controllerNamespace' => 'App\\Http\\Controller',
    'components' => [
        'request' => [
            'cookieValidationKey' => getenv('COOKIE_VALIDATION_KEY') ?: '',
            'parsers' => ['application/json' => yii\web\JsonParser::class],
        ],
        'response' => ['format' => yii\web\Response::FORMAT_JSON],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET health/live' => 'health/live',
                'GET health/ready' => 'health/ready',
                'GET api/v1/requests' => 'request/index',
                'POST api/v1/requests' => 'request/create',
            ],
        ],
    ],
]);
