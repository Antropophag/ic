<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\RequestLifecycleCommand;
use App\Domain\Request\RequestAction;
use yii\base\Model;

final class ReasonedLockVersionRequest extends Model
{
    public mixed $reason = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['reason', 'filter', 'filter' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value],
            ['reason', 'required'],
            ['reason', 'string', 'max' => 5000],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function toLifecycleCommand(int $requestId, int $actorId, RequestAction $action): RequestLifecycleCommand
    {
        return new RequestLifecycleCommand(
            $requestId,
            (int) $this->lockVersion,
            $actorId,
            $action,
            (string) $this->reason,
        );
    }
}
