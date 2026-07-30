<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class RejectRequestInput extends Model
{
    public mixed $reason = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['reason', 'filter', 'filter' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value],
            ['reason', 'string', 'max' => 5000],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
