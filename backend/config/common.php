<?php

declare(strict_types=1);

$env = static fn (string $name, ?string $default = null): string =>
    (($value = getenv($name)) !== false ? $value : $default)
    ?? throw new RuntimeException("Required environment variable {$name} is missing");
$documentStoragePath = getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents';
$lizaAiEnabled = getenv('LIZA_AI_ENABLED') === '1';
$lizaToken = getenv('LIZA_TOKEN') ?: '';
if ($lizaAiEnabled && $lizaToken === '') {
    throw new RuntimeException('Required environment variable LIZA_TOKEN is missing when LIZA_AI_ENABLED=1');
}

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
                        $documentStoragePath,
                    ),
                ),
            App\Application\Request\Port\OpinionRenderer::class => App\Infrastructure\Document\OpinionRendererAdapter::class,
            App\Application\Request\Port\ReportLifecycleGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\ReportLifecyclePersistenceAdapter(
                    Yii::$app->db,
                    new App\Infrastructure\Document\DocumentStorage(
                        $documentStoragePath,
                    ),
                ),
            App\Application\Request\Port\RequestCreationGateway::class => static fn () =>
                new App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter(Yii::$app->db),
            App\Application\Ai\AiConversationPort::class => static fn () =>
                new App\Infrastructure\Ai\DatabaseAiConversationStore(Yii::$app->db),
            App\Application\Ai\AiRequestLifecycle::class => static function () {
                $operationTimeout = min(300, max(1, (int) (getenv('LIZA_COMPLETION_TIMEOUT_SECONDS') ?: 300)));
                return new App\Infrastructure\Http\AiIdempotencyStore(
                    Yii::$app->db,
                    max(1, (int) (getenv('LIZA_PER_USER_CONCURRENCY') ?: 2)),
                    max(1, (int) (getenv('LIZA_GLOBAL_CONCURRENCY') ?: 4)),
                    $operationTimeout + 60,
                );
            },
            App\Application\Ai\TechnicalSpecificationDocumentPort::class => static function () use ($documentStoragePath) {
                $storage = new App\Infrastructure\Document\DocumentStorage(
                    $documentStoragePath,
                );
                return new App\Infrastructure\Ai\RequestTechnicalSpecificationDocuments(
                    Yii::$app->db,
                    new App\Infrastructure\Document\DocumentRepository(Yii::$app->db, $storage),
                    $storage,
                );
            },
            App\Infrastructure\Ai\OpenWebUiTransport::class => static fn () =>
                new App\Infrastructure\Ai\NativeOpenWebUiTransport(
                    getenv('LIZA_BASE_URL') ?: 'https://ai.shlz.ru',
                    $lizaToken,
                    (float) (getenv('LIZA_TIMEOUT_SECONDS') ?: 45),
                    (float) (getenv('LIZA_CONNECT_TIMEOUT_SECONDS') ?: 10),
                    completionTimeoutSeconds: (float) min(300, max(1, (int) (getenv('LIZA_COMPLETION_TIMEOUT_SECONDS') ?: 300))),
                ),
            App\Application\Ai\LizaPort::class => static fn () =>
                new App\Infrastructure\Ai\OpenWebUiLizaAdapter(
                    Yii::$container->get(App\Infrastructure\Ai\OpenWebUiTransport::class),
                    getenv('LIZA_MODEL') ?: 'ЛИЗА',
                    new App\Infrastructure\Ai\DatabaseAiFileCleanupQueue(Yii::$app->db),
                ),
            App\Application\Ai\AnalyzeTechnicalSpecification::class => static fn () =>
                new App\Application\Ai\AnalyzeTechnicalSpecification(
                    Yii::$container->get(App\Application\Ai\TechnicalSpecificationDocumentPort::class),
                    Yii::$container->get(App\Application\Ai\LizaPort::class),
                    Yii::$container->get(App\Application\Ai\AiConversationPort::class),
                    $lizaAiEnabled,
                ),
            App\Application\Ai\CreateTestSpecificationDraft::class => static fn () =>
                new App\Application\Ai\CreateTestSpecificationDraft(
                    Yii::$container->get(App\Application\Ai\TechnicalSpecificationDocumentPort::class),
                    Yii::$container->get(App\Application\Ai\LizaPort::class),
                    Yii::$container->get(App\Application\Ai\AiConversationPort::class),
                    $lizaAiEnabled,
                ),
        ],
    ],
    'components' => [
        'db' => [
            'class' => yii\db\Connection::class,
            'commandClass' => App\Infrastructure\Logging\ParameterSafeCommand::class,
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
