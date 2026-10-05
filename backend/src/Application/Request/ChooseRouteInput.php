<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class ChooseRouteInput extends Model
{
    public mixed $route = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            [['route', 'lockVersion'], 'required'],
            ['route', 'in', 'range' => ['act', 'protocol'], 'strict' => true],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
