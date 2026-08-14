<?php

declare(strict_types=1);

$env = static fn (string $name, ?string $default = null): string =>
    (($value = getenv($name)) !== false ? $value : $default)
    ?? throw new RuntimeException("Required environment variable {$name} is missing");

return [
    'id' => 'shlz-test-registry',
    'basePath' => dirname(__DIR__),
    'vendorPath' => dirname(__DIR__) . '/vendor',
    'bootstrap' => ['log'],
    'container' => [
        'definitions' => [
            App\Application\Request\Port\RequestCommentGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\RequestCommentPersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\RequestColorGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\RequestColorPersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\RequestDepartmentGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\RequestDepartmentPersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\RequestLifecycleGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\RequestLifecyclePersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\RequestCancellationGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\RequestCancellationPersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\ExecutorAssignmentGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\ExecutorAssignmentPersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\ExpertAssignmentGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\ExpertAssignmentPersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\SecurityDecisionGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\SecurityDecisionPersistenceAdapter(Yii::$app->db),
            App\Application\Request\Port\PublishOpinionGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\PublishOpinionPersistenceAdapter(
                    Yii::$app->db,
                    new App\Infrastructure\Document\DocumentStorage(
                        getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents',
                    ),
                ),
            App\Application\Request\Port\OpinionRenderer::class => App\Infrastructure\Document\OpinionRendererAdapter::class,
            App\Application\Request\Port\ReportLifecycleGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\ReportLifecyclePersistenceAdapter(
                    Yii::$app->db,
                    new App\Infrastructure\Document\DocumentStorage(
                        getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents',
                    ),
                ),
            App\Application\Request\Port\RequestCreationGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter(Yii::$app->db),
        ],
    ],
    'components' => [
        'db' => [
            'class' => yii\db\Connection::class,
            'dsn' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                $env('DB_HOST', 'mariadb'),
                $env('DB_PORT', '3306'),
                $env('DB_NAME', 'ic'),
            ),
            'username' => $env('DB_USER', 'ic'),
            'password' => $env('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'enableSchemaCache' => true,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [[
                'class' => App\Infrastructure\Logging\StderrTarget::class,
                'logVars' => [],
            ]],
        ],
    ],
];
