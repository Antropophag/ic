<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class ChangeDepartmentInput extends Model
{
    public mixed $department = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['department', 'filter', 'filter' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value],
            [['department', 'lockVersion'], 'required'],
            ['department', 'string', 'max' => 255],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
