<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class PublishOpinionInput extends Model
{
    public mixed $body = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['body', 'required'],
            ['body', 'string', 'min' => 10, 'max' => 20000],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }
}
