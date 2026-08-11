<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Admin\ListAuditEventsInput;
use App\Application\Admin\ListNotificationsInput;
use App\Application\Identity\AssignRoleInput;
use App\Application\Identity\CreateUserInput;
use App\Application\Identity\RevokeRoleInput;
use App\Domain\Identity\DuplicateAdLogin;
use App\Domain\Identity\RoleManagementDenied;
use App\Domain\Identity\RoleManagementPolicy;
use App\Domain\Identity\UserAdministrationTargetNotFound;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Identity\UserAdministrationRepository;
use App\Infrastructure\Admin\AuditQuery;
use App\Infrastructure\Admin\NotificationQuery;
use App\Infrastructure\Admin\SystemOverviewQuery;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Notification\Mailer;
use InvalidArgumentException;
use Yii;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

final class AdminController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = true;

        return parent::beforeAction($action);
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionUsers(): array
    {
        $this->authorize();
        return ['items' => $this->repository()->listUsers()];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionRoles(): array
    {
        $this->authorize();
        return ['items' => $this->repository()->listRoles()];
    }
    /** @return array<string, mixed> */
    public function actionSystemOverview(): array
    {
        $this->authorize();
        $storagePath = getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents';
        $ldapHost = $this->environmentValue('LDAP_HOST');
        $ldapPort = $this->environmentValue('LDAP_PORT') ?? '389';
        $ldapUseTls = strtolower($this->environmentValue('LDAP_USE_TLS') ?? 'false') === 'true';
        $smtpHost = $this->environmentValue('SMTP_HOST');
        $smtpPort = $this->environmentValue('SMTP_PORT') ?? '587';
        return (new SystemOverviewQuery([
            'name' => 'Регистратор заявок на проведение испытаний',
            'version' => $this->environmentValue('APP_VERSION'),
            'commitSha' => $this->environmentValue('APP_COMMIT_SHA'),
            'builtAt' => $this->environmentValue('APP_BUILD_TIMESTAMP'),
        ], [
            'database' => [],
            'ldap' => [
                'endpoint' => $ldapHost === null ? 'Not configured' : "{$ldapHost}:{$ldapPort}",
                'domain' => $this->environmentValue('LDAP_DOMAIN') ?? 'Not configured',
                'baseDn' => $this->environmentValue('LDAP_BASE_DN') ?? 'Not configured',
                'transportSecurity' => $ldapUseTls ? 'StartTLS' : 'None',
            ],
            'smtp' => [
                'endpoint' => $smtpHost === null ? 'Not configured' : "{$smtpHost}:{$smtpPort}",
                'transportSecurity' => $this->smtpSecurityLabel(),
                'sender' => $this->environmentValue('MAIL_FROM_ADDRESS') ?? 'Not configured',
            ],
            'storage' => [
                'path' => $storagePath,
            ],
        ], [
            'database' => static function (): array {
                $database = Yii::$app->db->createCommand('SELECT DATABASE()')->queryScalar();
                $version = Yii::$app->db->createCommand('SELECT VERSION()')->queryScalar();
                return [
                    'databaseName' => is_string($database) ? $database : 'Unknown',
                    'databaseVersion' => is_string($version) ? $version : 'Unknown',
                ];
            },
            'ldap' => function () use ($ldapUseTls): array {
                $host = $this->requiredEnvironmentValue('LDAP_HOST');
                $port = $this->environmentPort('LDAP_PORT', 389);
                $this->requiredEnvironmentValue('LDAP_DOMAIN');
                $this->requiredEnvironmentValue('LDAP_BASE_DN');
                $this->runWithTimeout(static function () use ($host, $port, $ldapUseTls): void {
                    $socket = @fsockopen(hostname: $host, port: $port, timeout: 5.0);
                    if ($socket === false) {
                        throw new \RuntimeException('LDAP endpoint is unreachable');
                    }
                    fclose($socket);
                    if (!$ldapUseTls) {
                        return;
                    }
                    $connection = @ldap_connect($host, $port);
                    if ($connection === false) {
                        throw new \RuntimeException('LDAP connection failed');
                    }
                    ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
                    ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
                    ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);
                    try {
                        if (!@ldap_start_tls($connection)) {
                            throw new \RuntimeException('LDAP StartTLS negotiation failed');
                        }
                    } finally {
                        @ldap_unbind($connection);
                    }
                }, 6);
                return [];
            },
            'smtp' => function (): array {
                $this->requiredEnvironmentValue('SMTP_HOST');
                $this->environmentPort('SMTP_PORT', 587);
                $this->requiredEnvironmentValue('MAIL_FROM_ADDRESS');
                (new Mailer())->checkConnection();
                return [];
            },
            'storage' => function () use ($storagePath): array {
                (new DocumentStorage($storagePath))->assertWritable();
                $freeBytes = disk_free_space($storagePath);
                return ['freeSpace' => is_float($freeBytes) ? $this->formatBytes($freeBytes) : 'Unknown'];
            },
        ]))->read();
    }

    /** @return array<string, mixed> */
    public function actionAuditEvents(): array
    {
        $this->authorize();
        $input = new ListAuditEventsInput();
        if (($errors = $this->queryValidationErrors($input)) !== null) {
            return $errors;
        }
        try {
            return $this->auditQuery()->findPage($this->auditFilters($input));
        } catch (InvalidArgumentException) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => ['cursor' => ['Некорректный курсор.']]];
        }
    }

    /** @return array<string, mixed> */
    public function actionNotifications(): array
    {
        $this->authorize();
        $input = new ListNotificationsInput();
        if (($errors = $this->queryValidationErrors($input)) !== null) {
            return $errors;
        }
        try {
            return $this->notificationQuery()->findPage($this->notificationFilters($input));
        } catch (InvalidArgumentException) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => ['cursor' => ['Некорректный курсор.']]];
        }
    }

    /** @return array<string, mixed> */
    public function actionCreateUser(): array
    {
        $actorId = $this->authorize();

        $input = new CreateUserInput();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $displayName = trim((string) $input->displayName);
        if ($displayName === '') {
            $displayName = (string) $input->adLogin;
        }

        try {
            $user = $this->repository()->createPlaceholder(
                (string) $input->adLogin,
                $displayName,
                $actorId,
            );
        } catch (DuplicateAdLogin $error) {
            throw new ConflictHttpException($error->getMessage());
        }

        Yii::$app->response->statusCode = 201;
        return $user;
    }

    /** @return array<string, mixed> */
    public function actionAssignRole(int $userId): array
    {
        $actorId = $this->authorize();

        $input = new AssignRoleInput();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        try {
            $roles = $this->repository()->assignRole($userId, (int) $input->roleId, $actorId);
        } catch (UserAdministrationTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }

        return ['items' => $roles];
    }

    /** @return array<string, mixed> */
    public function actionRevokeRole(int $userId, int $roleId): array
    {
        $actorId = $this->authorize();

        $input = new RevokeRoleInput();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        try {
            $roles = $this->repository()->revokeRole($userId, $roleId, $actorId, (string) $input->reason);
        } catch (UserAdministrationTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }

        return ['items' => $roles];
    }

    private function authorize(): int
    {
        $actorId = $this->currentUserId();
        try {
            (new RoleManagementPolicy())->assertCanManage(
                $this->isActiveUser($actorId),
                $this->repository()->rolesFor($actorId),
            );
        } catch (RoleManagementDenied $error) {
            throw new ForbiddenHttpException($error->getMessage());
        }

        return $actorId;
    }

    private function isActiveUser(int $userId): bool
    {
        return Yii::$app->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $userId],
        )->queryScalar() !== false;
    }

    private function currentUserId(): int
    {
        return (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
    }

    private function repository(): UserAdministrationRepository
    {
        return new UserAdministrationRepository(Yii::$app->db);
    }

    /** @return array{errors: array<string, list<string>>}|null */
    private function queryValidationErrors(\yii\base\Model $input): ?array
    {
        $input->load(Yii::$app->request->queryParams, '');
        if ($input->validate()) {
            return null;
        }
        Yii::$app->response->statusCode = 422;
        return ['errors' => $input->getErrors()];
    }

    /** @return array<string, mixed> */
    private function auditFilters(ListAuditEventsInput $input): array
    {
        return ['actorId' => $this->nullableInt($input->actorId), 'eventType' => $this->nullableString($input->eventType),
            'entityType' => $this->nullableString($input->entityType), 'entityId' => $this->nullableInt($input->entityId),
            'requestId' => $this->nullableInt($input->requestId), 'result' => (string) $input->result,
            'dateFrom' => $this->nullableString($input->dateFrom), 'dateTo' => $this->nullableString($input->dateTo),
            'limit' => (int) $input->limit, 'cursor' => $this->nullableString($input->cursor)];
    }

    /** @return array<string, mixed> */
    private function notificationFilters(ListNotificationsInput $input): array
    {
        return ['status' => $this->nullableString($input->status), 'requestId' => $this->nullableInt($input->requestId),
            'eventType' => $this->nullableString($input->eventType), 'recipient' => $this->nullableString($input->recipient),
            'dateFrom' => $this->nullableString($input->dateFrom), 'dateTo' => $this->nullableString($input->dateTo),
            'problematic' => $input->problematic === null || $input->problematic === '' ? null : (string) $input->problematic,
            'limit' => (int) $input->limit, 'cursor' => $this->nullableString($input->cursor)];
    }
    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
    private function auditQuery(): AuditQuery
    {
        return new AuditQuery(Yii::$app->db);
    }
    private function notificationQuery(): NotificationQuery
    {
        return new NotificationQuery(Yii::$app->db);
    }
    private function environmentValue(string $name): ?string
    {
        $value = getenv($name);
        return $value === false || trim($value) === '' ? null : trim($value);
    }
    private function requiredEnvironmentValue(string $name): string
    {
        return $this->environmentValue($name)
            ?? throw new \RuntimeException("Required environment variable {$name} is missing");
    }
    private function environmentPort(string $name, int $default): int
    {
        $value = $this->environmentValue($name) ?? (string) $default;
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new \RuntimeException("Environment variable {$name} must be a valid TCP port");
        }
        return $port;
    }
    private function smtpSecurityLabel(): string
    {
        return match (strtolower($this->environmentValue('SMTP_SECURE') ?? 'tls')) {
            'tls' => 'STARTTLS',
            'ssl' => 'TLS',
            'none' => 'None',
            default => 'Unknown',
        };
    }
    private function runWithTimeout(callable $operation, int $seconds): void
    {
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
            $operation();
            return;
        }
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): never {
            throw new \RuntimeException('LDAP probe timed out');
        }, false);
        pcntl_alarm($seconds);
        try {
            $operation();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);
        }
    }
    private function formatBytes(float $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            ++$index;
        }
        return number_format($bytes, $index === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$index];
    }
}
