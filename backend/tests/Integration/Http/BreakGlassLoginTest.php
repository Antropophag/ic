<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Http\Controller\AdminController;
use App\Http\Controller\AuthController;
use App\Infrastructure\Identity\BreakGlassAuthenticator;
use App\Infrastructure\Identity\BreakGlassConfiguration;
use App\Infrastructure\Identity\BreakGlassIdentityProvisioner;
use App\Infrastructure\Identity\CurrentUser;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\Request;
use yii\web\Session;
use yii\web\ForbiddenHttpException;

final class BreakGlassLoginTest extends IntegrationTestCase
{
    private const LOGIN = 'Emergency.Admin';
    private const PASSWORD = 'controller integration password';

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];
    private ?string $storagePath = null;

    protected function tearDown(): void
    {
        if (Yii::$app !== null) {
            Yii::$app->session->destroy();
            Yii::$app->errorHandler->unregister();
            Yii::$app = null;
        }
        try {
            foreach ($this->originalEnvironment as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
            if ($this->storagePath !== null && is_dir($this->storagePath)) {
                $this->removeDirectory($this->storagePath);
            }
        } finally {
            unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            parent::tearDown();
        }
    }

    public function testBreakGlassLoginCreatesOrdinaryAdministratorSession(): void
    {
        $this->configureEnvironment();
        $this->createApplication(self::LOGIN, self::PASSWORD);
        $oldSessionId = Yii::$app->session->id;

        $response = (new AuthController('auth', Yii::$app))->actionLogin();

        $userId = (int) $this->scalar(
            'SELECT id FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        );
        self::assertSame($userId, Yii::$app->session->get('userId'));
        self::assertNotSame($oldSessionId, Yii::$app->session->id);
        self::assertSame($userId, (new CurrentUser($this->db()))->id(Yii::$app->request));
        self::assertSame($userId, $response['user']['id']);
        self::assertSame(['administrator'], $response['user']['roles']);

        $adminResponse = (new AdminController('admin', Yii::$app))->actionUsers();
        self::assertArrayHasKey('items', $adminResponse);
        $overview = (new AdminController('admin', Yii::$app))->actionSystemOverview();
        self::assertSame('operational', $overview['services']['database']['status']);
        self::assertSame('error', $overview['services']['ldap']['status']);
        self::assertSame('unused.invalid:389', $overview['services']['ldap']['details']['endpoint']);
        self::assertArrayHasKey('endpoint', $overview['services']['smtp']['details']);
        self::assertArrayHasKey('path', $overview['services']['storage']['details']);
        self::assertStringNotContainsString('synthetic smtp password', json_encode($overview, JSON_THROW_ON_ERROR));
        $payload = $this->scalar(
            "SELECT payload_json FROM {{%audit_events}} WHERE event_type = 'authentication.break_glass_succeeded' "
            . 'AND actor_id = :actor_id',
            [':actor_id' => $userId],
        );
        self::assertIsString($payload);
        self::assertSame('192.0.2.20', json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['ip']);
    }

    public function testWrongBreakGlassPasswordReturnsGenericAuthenticationError(): void
    {
        $this->configureEnvironment();
        $this->createApplication(self::LOGIN, 'wrong password');

        $response = (new AuthController('auth', Yii::$app))->actionLogin();

        self::assertSame(401, Yii::$app->response->statusCode);
        self::assertSame([
            'errors' => ['login' => ['Неверный логин или пароль.']],
            'ruleId' => 'AUTH-001',
        ], $response);
        self::assertNull(Yii::$app->session->get('userId'));
    }

    public function testNonAdministratorCannotReadSystemOverview(): void
    {
        $this->configureEnvironment();
        $this->createApplication('employee', 'unused');
        $userId = $this->createUser('overview.employee', 'Обычный сотрудник');
        $this->grantRole($userId, 'employee');
        Yii::$app->session->set('userId', $userId);
        $this->expectException(ForbiddenHttpException::class);
        (new AdminController('admin', Yii::$app))->actionSystemOverview();
    }

    public function testMissingServiceConfigurationDoesNotHideIndependentResults(): void
    {
        $this->configureEnvironment();
        $this->createApplication(self::LOGIN, self::PASSWORD);
        (new AuthController('auth', Yii::$app))->actionLogin();
        putenv('LDAP_HOST');
        putenv('SMTP_HOST');

        $overview = (new AdminController('admin', Yii::$app))->actionSystemOverview();

        self::assertSame('operational', $overview['services']['database']['status']);
        self::assertSame('error', $overview['services']['ldap']['status']);
        self::assertSame('error', $overview['services']['smtp']['status']);
        self::assertSame('Not configured', $overview['services']['ldap']['details']['endpoint']);
        self::assertSame('Not configured', $overview['services']['smtp']['details']['endpoint']);
        self::assertSame('Not configured', $overview['services']['smtp']['details']['transportSecurity']);
    }

    public function testLdapStartTlsProbeReturnsAfterBoundedTimeout(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($server, $errorMessage);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        $serverCode = <<<'PHP'
$server = fopen('php://fd/3', 'r+');
for ($connectionNumber = 0; $connectionNumber < 2; ++$connectionNumber) {
    $connection = stream_socket_accept($server, 10);
    if (is_resource($connection)) {
        if ($connectionNumber === 1) {
            sleep(15);
        }
        fclose($connection);
    }
}
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $serverCode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => $server],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            $this->configureEnvironment();
            putenv('LDAP_HOST=127.0.0.1');
            putenv("LDAP_PORT={$port}");
            putenv('LDAP_USE_TLS=true');
            $this->createApplication(self::LOGIN, self::PASSWORD);
            (new AuthController('auth', Yii::$app))->actionLogin();
            $startedAt = microtime(true);

            $overview = (new AdminController('admin', Yii::$app))->actionSystemOverview();

            self::assertSame('error', $overview['services']['ldap']['status']);
            self::assertLessThan(8.0, microtime(true) - $startedAt);
        } finally {
            fclose($server);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
    }

    private function configureEnvironment(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/ic-system-overview-' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0700, true);
        $values = [
            'BREAK_GLASS_LOGIN' => self::LOGIN,
            'BREAK_GLASS_PASSWORD_HASH' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'LDAP_HOST' => 'unused.invalid',
            'LDAP_PORT' => '389',
            'LDAP_DOMAIN' => 'unused.invalid',
            'LDAP_BASE_DN' => 'DC=unused,DC=invalid',
            'LDAP_USE_TLS' => 'false',
            'SMTP_HOST' => 'smtp.invalid',
            'SMTP_PORT' => '2525',
            'SMTP_USERNAME' => 'synthetic@example.invalid',
            'SMTP_PASSWORD' => 'synthetic smtp password',
            'SMTP_SECURE' => 'none',
            'MAIL_FROM_ADDRESS' => 'sender@example.invalid',
            'DOCUMENT_STORAGE_PATH' => $this->storagePath,
        ];
        foreach ($values as $name => $value) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv("{$name}={$value}");
        }
        (new BreakGlassIdentityProvisioner(
            $this->db(),
            BreakGlassConfiguration::fromEnvironment(),
        ))->provision();
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- isolated fixture under a random test directory
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function createApplication(string $login, string $password): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.20';
        $_SERVER['HTTP_USER_AGENT'] = 'Controller integration browser';
        $application = new Application([
            'id' => 'break-glass-login-test',
            'basePath' => dirname(__DIR__, 4),
            'components' => [
                'db' => $this->db(),
                'request' => [
                    'class' => Request::class,
                    'cookieValidationKey' => 'break-glass-login-test-cookie-key',
                ],
                'session' => [
                    'class' => Session::class,
                    'useCookies' => false,
                ],
            ],
        ]);
        $application->request->headers->set('Content-Type', 'application/json');
        $application->request->headers->set('X-Forwarded-For', '203.0.113.99');
        $application->request->setRawBody(json_encode([
            'login' => $login,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));
        $application->session->setId('known-session-id-before-login');
        $application->session->open();
    }
}
