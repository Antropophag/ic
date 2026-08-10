<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import;

use App\Application\Import\BitrixSnapshotReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BitrixSnapshotReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ic-bitrix-reader-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testReadsVerifiedSnapshotAndStreamsElements(): void
    {
        $this->writeSnapshot([$this->user('7')]);

        $snapshot = (new BitrixSnapshotReader())->read($this->directory);

        self::assertSame(114, $snapshot['listId']);
        self::assertSame('7', $snapshot['users']['7']->bitrixId);
        self::assertSame([['ID' => '42']], iterator_to_array($snapshot['elements'], false));
    }

    public function testRejectsDuplicateUserIds(): void
    {
        $this->writeSnapshot([$this->user('7'), $this->user('7')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate snapshot user ID 7 at index 1.');
        (new BitrixSnapshotReader())->read($this->directory);
    }

    /** @param list<array<string, mixed>> $users */
    private function writeSnapshot(array $users): void
    {
        $files = [
            'fields.json' => '{}',
            'elements.jsonl' => "{\"ID\":\"42\"}\n",
            'users.json' => json_encode($users, JSON_THROW_ON_ERROR),
        ];
        $metadata = [];
        foreach ($files as $name => $contents) {
            file_put_contents($this->directory . '/' . $name, $contents);
            $metadata[$name] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        file_put_contents($this->directory . '/manifest.json', json_encode([
            'complete' => true,
            'formatVersion' => 2,
            'source' => ['listId' => 114],
            'files' => $metadata,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function user(string $id): array
    {
        return [
            'ID' => $id,
            'EMAIL' => "user{$id}@example.test",
            'NAME' => 'Иван',
            'LAST_NAME' => 'Иванов',
            'SECOND_NAME' => '',
            'ACTIVE' => true,
            'WORK_POSITION' => '',
        ];
    }
}
