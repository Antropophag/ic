<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\CancelRequestCommand;
use App\Domain\Request\RequestAction;
use yii\base\Model;

final class CancelRequest extends Model
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

    public function toCommand(int $requestId, int $actorId, RequestAction $action): CancelRequestCommand
    {
        return new CancelRequestCommand(
            $requestId,
            (int) $this->lockVersion,
            $actorId,
            $action,
            (string) $this->reason,
        );
    }
}
