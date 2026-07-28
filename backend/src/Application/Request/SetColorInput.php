<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestColor;
use yii\base\Model;

final class SetColorInput extends Model
{
    public mixed $color = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['color', 'required'],
            ['color', 'in', 'range' => array_map(static fn (RequestColor $color): string => $color->value, RequestColor::cases())],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
