<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\RequestLifecycleCommand;
use App\Domain\Request\RequestAction;
use yii\base\Model;

final class LockVersionRequest extends Model
{
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function toLifecycleCommand(int $requestId, int $actorId, RequestAction $action): RequestLifecycleCommand
    {
        return new RequestLifecycleCommand($requestId, (int) $this->lockVersion, $actorId, $action);
    }
}
