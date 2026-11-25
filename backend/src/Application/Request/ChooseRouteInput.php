<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class ChooseRouteInput extends Model
{
    public mixed $route = null;
    public mixed $lockVersion = null;
    public mixed $securityReviewRequired = true;

    public function rules(): array
    {
        return [
            [['route', 'lockVersion'], 'required'],
            ['route', 'in', 'range' => ['act', 'protocol'], 'strict' => true],
            ['lockVersion', 'integer', 'min' => 1],
            ['securityReviewRequired', 'boolean', 'trueValue' => true, 'falseValue' => false, 'strict' => true, 'skipOnEmpty' => false],
        ];
    }
}
