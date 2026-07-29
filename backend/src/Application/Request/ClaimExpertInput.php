<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class ClaimExpertInput extends Model
{
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
