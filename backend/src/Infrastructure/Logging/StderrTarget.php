<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use yii\log\LogRuntimeException;
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
            $written = $this->write($text);
            if ($written === false || $written !== strlen($text)) {
                throw new LogRuntimeException('Unable to export complete log message to stderr.');
            }
        }
    }

    protected function write(string $message): int|false
    {
        return file_put_contents('php://stderr', $message, FILE_APPEND);
    }
}
