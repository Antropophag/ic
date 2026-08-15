<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Yii;
use yii\db\Command;
use yii\db\Exception;

/**
 * Keeps bind parameter values out of query, profile, and DB exception messages.
 */
final class ParameterSafeCommand extends Command
{
    /** @return array{bool, string|null} */
    protected function logQuery($category): array
    {
        $sql = $this->getSql();
        if ($this->db->enableLogging) {
            Yii::info($sql, $category);
        }

        return [$this->db->enableProfiling, $sql];
    }

    protected function internalExecute($rawSql): void
    {
        try {
            parent::internalExecute($rawSql);
        } catch (Exception $error) {
            $errorClass = $error::class;
            $sqlState = isset($error->errorInfo[0]) ? (string) $error->errorInfo[0] : 'unknown';
            $driverCode = isset($error->errorInfo[1]) ? (string) $error->errorInfo[1] : 'unknown';
            $message = "Database query failed (SQLSTATE {$sqlState}, driver code {$driverCode})."
                . "\nThe SQL being executed was: {$rawSql}";

            throw new $errorClass($message, [$sqlState, $driverCode], $driverCode);
        }
    }
}
