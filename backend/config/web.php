<?php

declare(strict_types=1);

$common = require __DIR__ . '/common.php';
$application = [
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
                'GET api/v1/auth/me' => 'auth/me',
                'POST api/v1/auth/login' => 'auth/login',
                'POST api/v1/auth/logout' => 'auth/logout',
                'GET api/v1/requests' => 'request/index',
                'GET api/v1/requests/dashboard' => 'request/dashboard',
                'GET api/v1/requests/events' => 'request/events',
                'GET api/v1/requests/<id:\\d+>' => 'request/view',
                'POST api/v1/requests/<id:\\d+>/comments' => 'request/add-comment',
                'GET api/v1/requests/<id:\\d+>/comments' => 'request/comments',
                'POST api/v1/requests/<id:\\d+>/documents' => 'request/upload-document',
                'POST api/v1/requests/<id:\\d+>/report' => 'request/upload-report',
                'POST api/v1/requests/<id:\\d+>/report/delete' => 'request/delete-report',
                'GET api/v1/requests/<id:\\d+>/report-document-drafts/test-act' => 'request/prepare-test-act',
                'POST api/v1/requests/<id:\\d+>/report-document-drafts/test-act' => 'request/generate-test-act',
                'GET api/v1/document-versions/<id:\\d+>/download' => 'request/download-document',
                'GET api/v1/document-links/<token:[a-f0-9]{64}>/download' => 'request/download-document-link',
                'GET api/v1/executors' => 'request/executors',
                'GET api/v1/experts' => 'request/experts',
                'POST api/v1/requests' => 'request/create',
                'POST api/v1/requests/<id:\\d+>/executor' => 'request/assign-executor',
                'POST api/v1/requests/<id:\\d+>/expert/claim' => 'request/claim-expert',
                'POST api/v1/requests/<id:\\d+>/expert/reassign' => 'request/reassign-expert',
                'POST api/v1/requests/<id:\\d+>/opinion' => 'request/publish-opinion',
                'POST api/v1/requests/<id:\\d+>/security-decision' => 'request/security-decision',
                'POST api/v1/requests/<id:\\d+>/start' => 'request/start',
                'POST api/v1/requests/<id:\\d+>/suspend' => 'request/suspend',
                'POST api/v1/requests/<id:\\d+>/resume' => 'request/resume',
                'POST api/v1/requests/<id:\\d+>/color' => 'request/set-color',
                'POST api/v1/requests/<id:\\d+>/department' => 'request/change-department',
                'POST api/v1/requests/<id:\\d+>/reject' => 'request/reject',
                'POST api/v1/requests/<id:\\d+>/withdraw' => 'request/withdraw',
                'GET api/v1/admin/users' => 'admin/users',
                'POST api/v1/admin/users' => 'admin/create-user',
                'GET api/v1/admin/roles' => 'admin/roles',
                'GET api/v1/admin/audit-events' => 'admin/audit-events',
                'GET api/v1/admin/notifications' => 'admin/notifications',
                'GET api/v1/admin/system-overview' => 'admin/system-overview',
                'POST api/v1/admin/users/<userId:\\d+>/roles' => 'admin/assign-role',
                'POST api/v1/admin/users/<userId:\\d+>/roles/<roleId:\\d+>/revoke' => 'admin/revoke-role',
            ],
        ],
    ],
];
$deploymentFile = '/app/deployment/web.php';
$deployment = is_file($deploymentFile) ? require $deploymentFile : [];

return yii\helpers\ArrayHelper::merge($common, $application, $deployment);
