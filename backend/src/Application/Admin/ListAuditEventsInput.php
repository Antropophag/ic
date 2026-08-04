<?php

declare(strict_types=1);

namespace App\Application\Admin;

use yii\base\Model;

final class ListAuditEventsInput extends Model
{
    public mixed $actorId = null;
    public mixed $eventType = null;
    public mixed $entityType = null;
    public mixed $entityId = null;
    public mixed $requestId = null;
    public mixed $result = 'all';
    public mixed $dateFrom = null;
    public mixed $dateTo = null;
    public mixed $limit = 50;
    public mixed $cursor = null;

    public function rules(): array
    {
        return [
            [['actorId', 'entityId', 'requestId'], 'integer', 'min' => 1, 'skipOnEmpty' => true],
            [['eventType', 'entityType'], 'string', 'max' => 64, 'skipOnEmpty' => true],
            ['result', 'in', 'range' => ['all', 'denied']],
            [['dateFrom', 'dateTo'], 'date', 'format' => 'php:Y-m-d', 'skipOnEmpty' => true],
            ['limit', 'integer', 'min' => 1, 'max' => 100],
            ['cursor', 'string', 'max' => 256, 'skipOnEmpty' => true],
            ['cursor', 'match', 'pattern' => '/^[A-Za-z0-9_-]+$/', 'skipOnEmpty' => true],
        ];
    }
}
