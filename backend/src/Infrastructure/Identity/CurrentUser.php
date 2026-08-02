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
        $identityHeader = Yii::$app->params['identityHeader'] ?? null;
        if (is_string($identityHeader) && $identityHeader !== '') {
            $header = $request->headers->get($identityHeader);
            $id = filter_var($header, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0 && $this->isActive($id)) {
                return $id;
            }
        }

        $sessionUserId = Yii::$app->session->get('userId');
        if (is_int($sessionUserId)) {
            // AUTH-003 проверяется на каждый запрос, а не только при входе —
            // отключение локального профиля должно немедленно обрывать уже
            // открытую сессию, а не только блокировать следующий логин.
            if ($this->isActive($sessionUserId)) {
                return $sessionUserId;
            }
        }

        throw new UnauthorizedHttpException('Authentication required');
    }

    private function isActive(int $userId): bool
    {
        $isActive = $this->db->createCommand(
            'SELECT is_active FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        )->queryScalar();

        return $isActive !== false && (bool) $isActive;
    }
}
