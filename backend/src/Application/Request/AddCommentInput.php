<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class AddCommentInput extends Model
{
    public ?string $body = null;

    public function rules(): array
    {
        return [
            ['body', 'trim'],
            ['body', 'required'],
            ['body', 'string', 'max' => 10000],
        ];
    }
}
