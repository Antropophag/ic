<?php

declare(strict_types=1);

namespace App\Application\Identity;

use yii\base\Model;

final class CreateUserInput extends Model
{
    public ?string $adLogin = null;
    public ?string $displayName = null;

    public function rules(): array
    {
        return [
            [['adLogin', 'displayName'], 'trim'],
            ['adLogin', 'required'],
            ['adLogin', 'string', 'max' => 128],
            // sAMAccountName: буквы, цифры, точка, дефис, подчёркивание —
            // без @ и пробелов, чтобы не завести профиль по email/UPN
            // вместо чистого логина (bind в NativeLdapClient сам добавит
            // домен через "{login}@{domain}").
            ['adLogin', 'match', 'pattern' => '/^[A-Za-z0-9._-]+$/'],
            // displayName необязателен: он всё равно будет перезаписан
            // реальными данными из AD при первом входе через LDAP
            // (find-or-create по ad_login) — до этого момента используется
            // ad_login (см. AdminController::actionCreateUser()).
            ['displayName', 'string', 'max' => 255],
        ];
    }
}
