<?php

declare(strict_types=1);

namespace Tests\Support;

use yii\db\Connection;
use yii\db\Transaction;

final class TrackingConnection extends Connection
{
    public ?string $lastIsolationLevel = null;

    public function beginTransaction($isolationLevel = null): Transaction
    {
        $this->lastIsolationLevel = $isolationLevel;
        return parent::beginTransaction($isolationLevel);
    }
}
