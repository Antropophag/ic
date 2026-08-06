<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bitrix;

use App\Infrastructure\Bitrix\BitrixListClient;
use App\Infrastructure\Bitrix\BitrixSnapshotExporter;
use App\Infrastructure\Bitrix\BitrixTransport;
use App\Infrastructure\Bitrix\BitrixUserClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BitrixSnapshotExporterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ic-bitrix-snapshot-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testWritesCompletePrivateSnapshotAndManifest(): void
    {
        $transport = new class () implements BitrixTransport {
            /** @var list<array{string, array<string, mixed>}> */
            public array $calls = [];

            public function call(string $method, array $parameters = []): array
            {
                $this->calls[] = [$method, $parameters];
                if ($method === 'lists.field.get') {
                    return ['result' => ['PROPERTY_648' => ['NAME' => 'Документы']]];
                }
                if ($method === 'user.get') {
                    return ['result' => [[
                        'ID' => '77',
                        'ACTIVE' => 'Y',
                        'NAME' => 'Иван',
                        'LAST_NAME' => 'Иванов',
                        'EMAIL' => 'ivanov@example.test',
                        'PERSONAL_PHONE' => 'must-not-be-snapshotted',
                    ]]];
                }
                $details = json_encode(['creator' => ['ID' => '77']], JSON_THROW_ON_ERROR);
                return ($parameters['start'] ?? 0) === 0
                    ? ['result' => [['ID' => '1', 'DETAIL_TEXT' => $details]], 'next' => 50, 'total' => 2]
                    : ['result' => [['ID' => '2', 'DETAIL_TEXT' => $details]], 'total' => 2];
            }
        };
        $destination = $this->directory . '/snapshot';

        $manifest = (new BitrixSnapshotExporter())->export(
            new BitrixListClient($transport, 'lists', 114, 0),
            new BitrixUserClient($transport),
            $destination,
            'lists',
            114,
        );

        self::assertDirectoryExists($destination);
        self::assertSame(2, $manifest['formatVersion']);
        self::assertDirectoryDoesNotExist($destination . '.partial');
        self::assertSame(2, $manifest['pages']);
        self::assertSame(2, $manifest['records']);
        self::assertSame(2, $manifest['sourceTotal']);
        self::assertSame(1, $manifest['users']);
        self::assertContains('PROPERTY_648', $manifest['selectedFields']);
        self::assertContains('PROPERTY_648', $transport->calls[1][1]['SELECT']);
        self::assertSame('600', substr(sprintf('%o', fileperms($destination . '/elements.jsonl')), -3));
        self::assertStringNotContainsString('PERSONAL_PHONE', (string) file_get_contents($destination . '/users.json'));
        self::assertSame(
            hash_file('sha256', $destination . '/users.json'),
            $manifest['files']['users.json']['sha256'],
        );
        self::assertSame(
            hash_file('sha256', $destination . '/elements.jsonl'),
            $manifest['files']['elements.jsonl']['sha256'],
        );
    }

    public function testLeavesClearlyPartialSnapshotAfterSourceFailure(): void
    {
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                if ($method === 'lists.field.get') {
                    return ['result' => []];
                }
                if (($parameters['start'] ?? 0) === 0) {
                    return ['result' => [['ID' => '1']], 'next' => 50];
                }
                throw new RuntimeException('source unavailable');
            }
        };
        $destination = $this->directory . '/snapshot';

        try {
            (new BitrixSnapshotExporter())->export(
                new BitrixListClient($transport, 'lists', 114, 0),
                new BitrixUserClient($transport),
                $destination,
                'lists',
                114,
            );
            self::fail('Exporter was expected to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('partial data remains', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($destination);
        self::assertDirectoryExists($destination . '.partial');
        self::assertFileDoesNotExist($destination . '.partial/manifest.json');
        self::assertSame("{\"ID\":\"1\"}\n", file_get_contents($destination . '.partial/elements.jsonl'));
    }

    public function testRejectsChangingSourceTotalDuringFullRead(): void
    {
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                if ($method === 'lists.field.get') {
                    return ['result' => []];
                }
                return ($parameters['start'] ?? 0) === 0
                    ? ['result' => [['ID' => '1']], 'next' => 50, 'total' => 2]
                    : ['result' => [['ID' => '2']], 'total' => 3];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partial data remains');
        (new BitrixSnapshotExporter())->export(
            new BitrixListClient($transport, 'lists', 114, 0),
            new BitrixUserClient($transport),
            $this->directory . '/snapshot',
            'lists',
            114,
        );
    }

    public function testRefusesToWriteSensitiveSnapshotInsideGitWorkTree(): void
    {
        file_put_contents($this->directory . '/.git', 'gitdir: elsewhere');
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                return ['result' => []];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside a Git working tree');
        (new BitrixSnapshotExporter())->export(
            new BitrixListClient($transport, 'lists', 114, 0),
            new BitrixUserClient($transport),
            $this->directory . '/snapshot',
            'lists',
            114,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-owned random temporary directory
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
