<?php

declare(strict_types=1);

namespace App\Application\Identity;

use yii\base\Model;

final class RevokeRoleInput extends Model
{
    public mixed $reason = null;

    public function rules(): array
    {
        return [
            ['reason', 'filter', 'filter' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value],
            ['reason', 'required'],
            ['reason', 'string', 'max' => 5000],
        ];
    }
}
