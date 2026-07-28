<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class AssignExecutorInput extends Model
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
}
