<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

use RuntimeException;
use Throwable;

final class PrivateJsonReportWriter
{
    /** @param array<string, mixed> $report */
    public function write(string $destination, array $report): void
    {
        if (file_exists($destination)) {
            throw new RuntimeException('Report destination already exists.');
        }
        $directory = dirname($destination);
        if (!is_dir($directory)) {
            throw new RuntimeException('Report destination directory does not exist.');
        }
        $temporary = $destination . '.partial';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false || !chmod($temporary, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Cannot create a private report file.');
        }
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $contents = $json . "\n";
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write the inventory report.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Cannot write the inventory report.');
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $destination)) {
                throw new RuntimeException('Cannot publish the inventory report.');
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw $exception;
        }
    }
}
