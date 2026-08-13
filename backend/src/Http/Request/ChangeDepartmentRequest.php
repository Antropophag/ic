<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\ChangeRequestDepartmentCommand;
use yii\base\Model;

final class ChangeDepartmentRequest extends Model
{
    public mixed $department = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['department', 'filter', 'filter' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value],
            [['department', 'lockVersion'], 'required'],
            ['department', 'string', 'max' => 255],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function toCommand(int $requestId, int $actorId): ChangeRequestDepartmentCommand
    {
        return new ChangeRequestDepartmentCommand(
            $requestId,
            (string) $this->department,
            (int) $this->lockVersion,
            $actorId,
        );
    }
}
