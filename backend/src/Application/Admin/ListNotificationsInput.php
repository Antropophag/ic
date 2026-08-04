<?php

declare(strict_types=1);

namespace App\Application\Admin;

use yii\base\Model;

final class ListNotificationsInput extends Model
{
    public mixed $status = null;
    public mixed $requestId = null;
    public mixed $eventType = null;
    public mixed $recipient = null;
    public mixed $dateFrom = null;
    public mixed $dateTo = null;
    public mixed $problematic = null;
    public mixed $limit = 50;
    public mixed $cursor = null;

    public function rules(): array
    {
        return [
            ['status', 'in', 'range' => ['pending', 'sending', 'sent', 'failed'], 'skipOnEmpty' => true],
            ['requestId', 'integer', 'min' => 1, 'skipOnEmpty' => true],
            ['eventType', 'string', 'max' => 64, 'skipOnEmpty' => true],
            ['recipient', 'string', 'max' => 255, 'skipOnEmpty' => true],
            [['dateFrom', 'dateTo'], 'date', 'format' => 'php:Y-m-d', 'skipOnEmpty' => true],
            ['problematic', 'boolean', 'trueValue' => '1', 'falseValue' => '0', 'strict' => true, 'skipOnEmpty' => true],
            ['limit', 'integer', 'min' => 1, 'max' => 100],
            ['cursor', 'string', 'max' => 256, 'skipOnEmpty' => true],
            ['cursor', 'match', 'pattern' => '/^[A-Za-z0-9_-]+$/', 'skipOnEmpty' => true],
        ];
    }
}
