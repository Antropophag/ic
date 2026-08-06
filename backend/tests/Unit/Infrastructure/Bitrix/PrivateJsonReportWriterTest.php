<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bitrix;

use App\Infrastructure\Bitrix\PrivateJsonReportWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrivateJsonReportWriterTest extends TestCase
{
    public function testPublishesPrivateReportWithoutPartialFile(): void
    {
        $directory = sys_get_temp_dir() . '/ic-bitrix-report-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $destination = $directory . '/inventory.json';
        try {
            (new PrivateJsonReportWriter())->write($destination, ['records' => 2]);

            self::assertSame("{\n    \"records\": 2\n}\n", file_get_contents($destination));
            self::assertSame('600', substr(sprintf('%o', fileperms($destination)), -3));
            self::assertFileDoesNotExist($destination . '.partial');
        } finally {
            if (file_exists($destination)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-owned random temporary directory
                unlink($destination);
            }
            rmdir($directory);
        }
    }

    public function testRefusesToOverwriteExistingReport(): void
    {
        $directory = sys_get_temp_dir() . '/ic-bitrix-report-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $destination = $directory . '/inventory.json';
        file_put_contents($destination, 'existing');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already exists');
            (new PrivateJsonReportWriter())->write($destination, ['records' => 2]);
        } finally {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-owned random temporary directory
            unlink($destination);
            rmdir($directory);
        }
    }
}
