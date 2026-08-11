<?php

declare(strict_types=1);

namespace App\Application\Document;

use yii\base\Model;

final class TestActInput extends Model
{
    public mixed $actNumber = null;
    public mixed $actDate = null;
    public mixed $basis = null;
    public mixed $result = null;

    public function rules(): array
    {
        return [
            [['actNumber', 'actDate', 'basis', 'result'], 'filter', 'filter' => 'trim'],
            [['actNumber', 'actDate', 'basis', 'result'], 'required'],
            ['actNumber', 'string', 'max' => 100],
            ['actDate', 'date', 'format' => 'php:d.m.Y', 'locale' => 'ru_RU', 'timeZone' => 'Europe/Moscow'],
            ['basis', 'string', 'max' => 1000],
            ['result', 'string', 'max' => 20000],
        ];
    }
}
