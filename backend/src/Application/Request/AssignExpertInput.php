<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class AssignExpertInput extends Model
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
}
