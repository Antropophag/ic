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

    protected function tearDown(): void
    {
        if (Yii::$app !== null) {
            Yii::$app->session->destroy();
            Yii::$app->errorHandler->unregister();
            Yii::$app = null;
        }
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        parent::tearDown();
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
        self::assertSame('unused.invalid:389', $overview['services']['ldap']['details']['Сервер']);
        self::assertArrayHasKey('Сервер', $overview['services']['smtp']['details']);
        self::assertArrayHasKey('Путь', $overview['services']['storage']['details']);
        self::assertStringNotContainsString((string) getenv('DB_PASSWORD'), json_encode($overview, JSON_THROW_ON_ERROR));
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

    private function configureEnvironment(): void
    {
        $values = [
            'BREAK_GLASS_LOGIN' => self::LOGIN,
            'BREAK_GLASS_PASSWORD_HASH' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'LDAP_HOST' => 'unused.invalid',
            'LDAP_PORT' => '389',
            'LDAP_DOMAIN' => 'unused.invalid',
            'LDAP_BASE_DN' => 'DC=unused,DC=invalid',
            'LDAP_USE_TLS' => 'false',
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
