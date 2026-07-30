<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\LoginInput;
use App\Infrastructure\Identity\AccountDisabled;
use App\Infrastructure\Identity\AuthenticationDenied;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Identity\LdapAuthenticator;
use App\Infrastructure\Ldap\LdapConnectionException;
use App\Infrastructure\Ldap\NativeLdapClient;
use App\Console\DevController;
use Yii;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;

final class AuthController extends Controller
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        // См. RequestController::beforeAction() — yii\rest\Controller
        // отключает CSRF по умолчанию, вне dev включаем явно. actionMe()
        // должен остаться доступным без токена (он же его и выдаёт), но
        // login/logout — это state-changing действия с cookie-сессией и
        // обязаны быть защищены (login CSRF мог бы аутентифицировать
        // жертву под чужой сессией).
        $this->enableCsrfValidation = YII_ENV !== 'dev';

        return parent::beforeAction($action);
    }

    /** @return array{csrfToken: string, devMode: bool, user: array<string, mixed>|null} */
    public function actionMe(): array
    {
        // Обращение к csrfToken гарантирует установку CSRF-cookie в ответе —
        // фронтенд должен получить токен до первой формы логина, иначе сам
        // логин будет отклонён проверкой CSRF (RequestController). devMode
        // решает, показывать ли фронтенду dev-переключатель или форму входа
        // — YII_ENV, а не признак production-сборки Vite: и smoke, и E2E
        // гоняют собранный фронтенд против backend с APP_ENV=dev.
        $csrfToken = Yii::$app->request->csrfToken;
        $devMode = YII_ENV === 'dev';

        try {
            $userId = (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
        } catch (\Throwable) {
            return ['csrfToken' => $csrfToken, 'devMode' => $devMode, 'user' => null];
        }

        return ['csrfToken' => $csrfToken, 'devMode' => $devMode, 'user' => $this->profile($userId)];
    }

    /**
     * Список dev-переключателя фронтенда. Фиксированные id ядра dev-аккаунтов
     * (DevController::CORE_USERS) не гарантированы на давно живущей демо-базе —
     * dev/seed мог отступить на seed-по-ad_login при конфликте id (issue про
     * dev/seed конфликт, PR #113), поэтому актуальные id резолвятся здесь из
     * БД по ad_login, а не берутся из фронтенда захардкоженными.
     *
     * Единственная проверка — YII_ENV === 'dev', как и у actionMe() выше:
     * это не забытая авторизация, а тот же самый пред-аутентификационный
     * эндпоинт (список актёров нужен фронтенду ДО выбора, под кем логиниться
     * через X-Dev-User-ID — авторизовывать не под кем). Данные — фиксированные
     * синтетические аккаунты (dev.*@example.invalid), не секрет; а сам
     * dev-режим уже принимает любой X-Dev-User-ID без проверки существования
     * (CurrentUser), так что этот список не расширяет модель угроз dev-стенда.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function actionDevUsers(): array
    {
        if (YII_ENV !== 'dev') {
            throw new NotFoundHttpException();
        }

        $adLogins = array_column(DevController::CORE_USERS, 'ad_login');
        $params = [];
        $placeholders = [];
        foreach ($adLogins as $i => $adLogin) {
            $placeholders[] = ":login{$i}";
            $params[":login{$i}"] = $adLogin;
        }
        $rows = Yii::$app->db->createCommand(
            'SELECT id, ad_login AS adLogin FROM {{%users}} WHERE ad_login IN (' . implode(',', $placeholders) . ')',
            $params,
        )->queryAll();
        $idByLogin = array_column($rows, 'id', 'adLogin');

        $items = [];
        foreach ($adLogins as $adLogin) {
            if (!isset($idByLogin[$adLogin])) {
                continue;
            }
            $items[] = $this->profile((int) $idByLogin[$adLogin]);
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function actionLogin(): array
    {
        $input = new LoginInput();
        $input->load(Yii::$app->request->bodyParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        try {
            $result = $this->authenticator()->authenticate((string) $input->login, (string) $input->password);
        } catch (AuthenticationDenied $error) {
            Yii::$app->response->statusCode = 401;
            return ['errors' => ['login' => ['Неверный логин или пароль.']], 'ruleId' => $error->ruleId];
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

    private function authenticator(): LdapAuthenticator
    {
        $useTls = strtolower((string) (getenv('LDAP_USE_TLS') ?: 'false')) === 'true';
        $client = new NativeLdapClient(
            $this->requiredEnv('LDAP_HOST'),
            $this->requiredPortEnv('LDAP_PORT'),
            $this->requiredEnv('LDAP_DOMAIN'),
            $this->requiredEnv('LDAP_BASE_DN'),
            $useTls,
        );

        return new LdapAuthenticator(Yii::$app->db, $client);
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
