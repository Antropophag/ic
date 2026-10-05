<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class CorrectSecurityDecisionInput extends Model
{
    public mixed $decision = null;
    public mixed $reason = null;
    public mixed $ticketReference = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            [['reason', 'ticketReference'], 'filter', 'filter' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value],
            [['decision', 'reason', 'ticketReference', 'lockVersion'], 'required'],
            ['decision', 'in', 'range' => ['approve', 'decline'], 'strict' => true],
            ['reason', 'string', 'max' => 5000],
            ['ticketReference', 'string', 'max' => 1000],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
