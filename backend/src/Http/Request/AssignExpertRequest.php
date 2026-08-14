<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\AssignExpertCommand;
use App\Application\Request\Command\ExpertAssignmentAction;
use yii\base\Model;

final class AssignExpertRequest extends Model
{
    public mixed $expertId = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['expertId', 'required'],
            ['expertId', 'integer', 'min' => 1],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function toCommand(int $requestId, int $actorId): AssignExpertCommand
    {
        return new AssignExpertCommand(
            ExpertAssignmentAction::Reassign,
            $requestId,
            (int) $this->expertId,
            (int) $this->lockVersion,
            $actorId,
        );
    }
}
