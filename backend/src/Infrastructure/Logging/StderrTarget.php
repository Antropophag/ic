<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use yii\log\Target;

/**
 * Writes Yii messages directly to the process stderr stream.
 *
 * FileTarget assumes a seekable, lockable file and is therefore unsuitable
 * for php://stderr under php-fpm. This target avoids file locking, size checks
 * and rotation; the container runtime owns collection and rotation instead.
 */
class StderrTarget extends Target
{
    public function export(): void
    {
        foreach ($this->messages as $message) {
            $text = $this->formatMessage($message) . PHP_EOL;
            // Logging must remain best-effort: a closed or full stderr stream
            // must not turn an otherwise successful request into HTTP 500.
            $this->write($text);
        }
    }

    protected function write(string $message): int|false
    {
        return file_put_contents('php://stderr', $message, FILE_APPEND);
    }
}
