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
                'GET api/v1/requests/<id:\\d+>' => 'request/view',
                'POST api/v1/requests/<id:\\d+>/comments' => 'request/add-comment',
                'GET api/v1/requests/<id:\\d+>/comments' => 'request/comments',
                'POST api/v1/requests/<id:\\d+>/documents' => 'request/upload-document',
                'POST api/v1/requests/<id:\\d+>/report' => 'request/upload-report',
                'GET api/v1/document-versions/<id:\\d+>/download' => 'request/download-document',
                'GET api/v1/executors' => 'request/executors',
                'GET api/v1/experts' => 'request/experts',
                'POST api/v1/requests' => 'request/create',
                'POST api/v1/requests/<id:\\d+>/executor' => 'request/assign-executor',
                'POST api/v1/requests/<id:\\d+>/expert' => 'request/assign-expert',
                'POST api/v1/requests/<id:\\d+>/opinion' => 'request/publish-opinion',
                'POST api/v1/requests/<id:\\d+>/security-decision' => 'request/security-decision',
                'POST api/v1/requests/<id:\\d+>/start' => 'request/start',
                'POST api/v1/requests/<id:\\d+>/color' => 'request/set-color',
            ],
        ],
    ],
]);
