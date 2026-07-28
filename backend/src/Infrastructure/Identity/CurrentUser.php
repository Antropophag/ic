<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use yii\web\Request;
use yii\web\UnauthorizedHttpException;

final class CurrentUser
{
    public function id(Request $request): int
    {
        // До подключения LDAP это позволяет интеграционным тестам и dev UI
        // задавать пользователя, но никогда не создаёт production-backdoor.
        if (YII_ENV === 'dev') {
            $id = filter_var($request->headers->get('X-Dev-User-ID'), FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0) {
                return $id;
            }
        }

        throw new UnauthorizedHttpException('Authentication required');
    }
}
