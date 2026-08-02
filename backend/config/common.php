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
