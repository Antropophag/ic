<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use Yii;
use yii\db\Connection;
use yii\web\Request;
use yii\web\UnauthorizedHttpException;

final class CurrentUser
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function id(Request $request): int
    {
        // Управляемые заголовки доступны только в dev/test: X-Test-User-ID
        // существует исключительно в test, а production игнорирует оба.
        if (in_array(YII_ENV, ['dev', 'test'], true)) {
            $header = YII_ENV === 'test'
                ? ($request->headers->get('X-Test-User-ID') ?? $request->headers->get('X-Dev-User-ID'))
                : $request->headers->get('X-Dev-User-ID');
            $id = filter_var($header, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0) {
                return $id;
            }
        }

        $sessionUserId = Yii::$app->session->get('userId');
        if (is_int($sessionUserId)) {
            // AUTH-003 проверяется на каждый запрос, а не только при входе —
            // отключение локального профиля должно немедленно обрывать уже
            // открытую сессию, а не только блокировать следующий логин.
            $isActive = $this->db->createCommand(
                'SELECT is_active FROM {{%users}} WHERE id = :id',
                [':id' => $sessionUserId],
            )->queryScalar();
            if ($isActive !== false && (bool) $isActive) {
                return $sessionUserId;
            }
        }

        throw new UnauthorizedHttpException('Authentication required');
    }
}
