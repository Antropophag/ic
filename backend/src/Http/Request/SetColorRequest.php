<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\SetRequestColorCommand;
use App\Domain\Request\RequestColor;
use yii\base\Model;

final class SetColorRequest extends Model
{
    public mixed $color = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['color', 'required'],
            ['color', 'string'],
            ['color', 'in', 'range' => array_map(static fn (RequestColor $color): string => $color->value, RequestColor::cases())],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function toCommand(int $requestId, int $actorId): SetRequestColorCommand
    {
        return new SetRequestColorCommand(
            $requestId,
            RequestColor::from((string) $this->color),
            (int) $this->lockVersion,
            $actorId,
        );
    }
}
