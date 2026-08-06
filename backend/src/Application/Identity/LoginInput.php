<?php

declare(strict_types=1);

namespace App\Application\Identity;

use yii\base\Model;

final class LoginInput extends Model
{
    public mixed $login = null;
    public mixed $password = null;

    public function rules(): array
    {
        return [
            [['login', 'password'], 'required'],
            ['login', 'string', 'max' => 128],
            ['password', 'string', 'max' => 512],
        ];
    }
}
