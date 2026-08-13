<?php

declare(strict_types=1);

namespace App\Application\Identity;

use yii\base\Model;

final class AssignRoleInput extends Model
{
    // mixed, а не ?int: типизированное свойство при несовместимом JSON-типе
    // (например, строка не из цифр) бросает TypeError уже при Model::load(),
    // до того как отработает валидатор 'integer' — тот же паттерн, что и у
    // остальных integer-полей HTTP request models,
    // иначе клиент получит 500 вместо контролируемого 422.
    public mixed $roleId = null;

    public function rules(): array
    {
        return [
            ['roleId', 'required'],
            ['roleId', 'integer', 'min' => 1],
        ];
    }
}
