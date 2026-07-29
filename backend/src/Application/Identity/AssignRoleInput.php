<?php

declare(strict_types=1);

namespace App\Application\Identity;

use yii\base\Model;

final class AssignRoleInput extends Model
{
    public ?int $roleId = null;

    public function rules(): array
    {
        return [
            ['roleId', 'required'],
            ['roleId', 'integer', 'min' => 1],
        ];
    }
}
