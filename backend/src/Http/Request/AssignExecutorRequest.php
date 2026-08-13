<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\AssignExecutorCommand;
use yii\base\Model;

final class AssignExecutorRequest extends Model
{
    public mixed $executorId = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['executorId', 'required'],
            ['executorId', 'integer', 'min' => 1],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function toCommand(int $requestId, int $actorId): AssignExecutorCommand
    {
        return new AssignExecutorCommand(
            $requestId,
            (int) $this->executorId,
            (int) $this->lockVersion,
            $actorId,
        );
    }
}
