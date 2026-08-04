<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\LoginInput;
use App\Infrastructure\Identity\AccountDisabled;
use App\Infrastructure\Identity\AuthenticationDenied;
use App\Infrastructure\Identity\BreakGlassAuthenticator;
use App\Infrastructure\Identity\BreakGlassConfiguration;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Identity\LdapAuthenticator;
use App\Infrastructure\Identity\LoginAuthenticator;
use App\Infrastructure\Ldap\LdapConnectionException;
use App\Infrastructure\Ldap\NativeLdapClient;
use Yii;
use yii\web\ServerErrorHttpException;

final class AuthController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        // yii\rest\Controller отключает CSRF по умолчанию. actionMe()
        // должен остаться доступным без токена (он же его и выдаёт), но
        // login/logout — это state-changing действия с cookie-сессией и
        // обязаны быть защищены (login CSRF мог бы аутентифицировать
        // жертву под чужой сессией).
        $this->enableCsrfValidation = true;

        return parent::beforeAction($action);
    }

    /** @return array{csrfToken: string, user: array<string, mixed>|null} */
    public function actionMe(): array
    {
        // Обращение к csrfToken гарантирует установку CSRF-cookie в ответе —
        // фронтенд должен получить токен до первой формы логина, иначе сам
        // логин будет отклонён проверкой CSRF.
        $csrfToken = Yii::$app->request->csrfToken;

        try {
            $userId = (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
        } catch (\Throwable) {
            return ['csrfToken' => $csrfToken, 'user' => null];
        }

        return ['csrfToken' => $csrfToken, 'user' => $this->profile($userId)];
    }

    /** @return array<string, mixed> */
    public function actionLogin(): array
    {
        $input = new LoginInput();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        try {
            $result = $this->authenticator()->authenticate(
                (string) $input->login,
                (string) $input->password,
                (string) Yii::$app->request->remoteIP,
                (string) Yii::$app->request->userAgent,
            );
        } catch (AuthenticationDenied) {
            Yii::$app->response->statusCode = 401;
            // Credential failures use one external contract regardless of
            // whether LDAP or break-glass handled the submitted login.
            return ['errors' => ['login' => ['Неверный логин или пароль.']], 'ruleId' => 'AUTH-001'];
        } catch (AccountDisabled $error) {
            Yii::$app->response->statusCode = 403;
            return ['errors' => ['login' => ['Учётная запись отключена в портале.']], 'ruleId' => $error->ruleId];
        } catch (LdapConnectionException $error) {
            Yii::error('LDAP connection failed during login: ' . $error->getMessage());
            throw new ServerErrorHttpException('LDAP сервер недоступен. Обратитесь к администратору.');
        }

        // Смена идентификатора сессии при входе — защита от session fixation.
        Yii::$app->session->regenerateID(true);
        Yii::$app->session->set('userId', $result['id']);

        return ['csrfToken' => Yii::$app->request->csrfToken, 'user' => $this->profile($result['id'])];
    }

    /** @return array{csrfToken: string, user: null} */
    public function actionLogout(): array
    {
        Yii::$app->session->remove('userId');
        Yii::$app->session->destroy();

        return ['csrfToken' => Yii::$app->request->csrfToken, 'user' => null];
    }

    /** @return array<string, mixed> */
    private function profile(int $userId): array
    {
        $row = Yii::$app->db->createCommand(
            'SELECT display_name AS displayName, email, department, position FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        )->queryOne();
        $roles = Yii::$app->db->createCommand(
            'SELECT r.code FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id WHERE ur.user_id = :id',
            [':id' => $userId],
        )->queryColumn();

        return [
            'id' => $userId,
            'displayName' => $row !== false ? $row['displayName'] : null,
            'email' => $row !== false ? $row['email'] : null,
            'department' => $row !== false ? $row['department'] : null,
            'position' => $row !== false ? $row['position'] : null,
            'roles' => $roles,
        ];
    }

    private function authenticator(): LoginAuthenticator
    {
        $useTls = strtolower((string) (getenv('LDAP_USE_TLS') ?: 'false')) === 'true';
        $client = new NativeLdapClient(
            $this->requiredEnv('LDAP_HOST'),
            $this->requiredPortEnv('LDAP_PORT'),
            $this->requiredEnv('LDAP_DOMAIN'),
            $this->requiredEnv('LDAP_BASE_DN'),
            $useTls,
        );

        return new LoginAuthenticator(
            new BreakGlassAuthenticator(Yii::$app->db, BreakGlassConfiguration::fromEnvironment()),
            new LdapAuthenticator(Yii::$app->db, $client),
        );
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Required environment variable {$name} is missing");
        }
        return $value;
    }

    private function requiredPortEnv(string $name): int
    {
        // Некорректный порт (пустая строка, буквы, вне диапазона) должен
        // явно ломать конфигурацию сервиса, а не тихо превращаться в 0 и
        // маскировать ошибку деплоя — это самый частый способ, которым
        // .env вида "LDAP_PORT=389 " или опечатка остаются незамеченными.
        $port = filter_var($this->requiredEnv($name), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new \RuntimeException("Environment variable {$name} must be a valid TCP port");
        }
        return $port;
    }
}
