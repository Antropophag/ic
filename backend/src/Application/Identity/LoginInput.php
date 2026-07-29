<?php

declare(strict_types=1);

namespace App\Application\Identity;

use yii\base\Model;

final class LoginInput extends Model
{
    public ?string $login = null;
    public ?string $password = null;

    public function rules(): array
    {
        return [
            [['login', 'password'], 'required'],
            ['login', 'string', 'max' => 128],
            ['password', 'string', 'max' => 512],
        ];
    }
}
