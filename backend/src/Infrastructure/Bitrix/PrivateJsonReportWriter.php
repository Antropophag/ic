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
        $permissions = fileperms($directory);
        if ($permissions === false || ($permissions & 0777) !== 0700) {
            throw new RuntimeException('Report destination directory must have permissions 0700.');
        }
        $temporary = $destination . '.partial';
        $previousUmask = umask(0077);
        try {
            $handle = fopen($temporary, 'x+b');
        } finally {
            umask($previousUmask);
        }
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
            if (!link($temporary, $destination)) {
                throw new RuntimeException('Cannot publish the inventory report.');
            }
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- removes the private publish temporary
            if (!unlink($temporary)) {
                throw new RuntimeException('Cannot remove the published report temporary file.');
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- removes a failed private report temporary
                unlink($temporary);
            }
            throw $exception;
        }
    }
}
