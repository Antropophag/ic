<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class SecurityDecisionInput extends Model
{
    public mixed $decision = null;
    public mixed $reason = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['decision', 'required'],
            ['decision', 'in', 'range' => ['approve', 'return']],
            ['reason', 'filter', 'filter' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value],
            ['reason', 'string', 'max' => 5000],
            ['reason', 'required', 'when' => fn (): bool => $this->decision === 'return'],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
