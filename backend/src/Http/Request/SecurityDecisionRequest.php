<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\DecideSecurityCommand;
use yii\base\Model;

final class SecurityDecisionRequest extends Model
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
            ['reason', 'validateApproveReason'],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function validateApproveReason(string $attribute): void
    {
        if ($this->decision === 'approve' && $this->{$attribute} !== null && $this->{$attribute} !== '') {
            $this->addError($attribute, 'Причина не допускается при согласовании.');
        }
    }

    public function toCommand(int $requestId, int $actorId): DecideSecurityCommand
    {
        return new DecideSecurityCommand(
            $requestId,
            $actorId,
            (string) $this->decision,
            $this->reason === null || $this->reason === '' ? null : (string) $this->reason,
            (int) $this->lockVersion,
        );
    }
}
