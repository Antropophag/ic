<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class ChangeDepartmentInput extends Model
{
    public ?string $department = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['department', 'filter', 'filter' => 'trim'],
            [['department', 'lockVersion'], 'required'],
            ['department', 'string', 'max' => 255],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
