<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import;

use App\Application\Import\BitrixSnapshotInventory;
use App\Infrastructure\Bitrix\BitrixListClient;
use App\Infrastructure\Bitrix\BitrixSnapshotExporter;
use App\Infrastructure\Bitrix\BitrixTransport;
use App\Infrastructure\Bitrix\BitrixUserClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BitrixSnapshotInventoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ic-bitrix-inventory-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testBuildsSanitizedOfflineInventory(): void
    {
        $secretUrl = 'https://example.bitrix24.ru/rest/1/secret/file.docx';
        $elements = [
            $this->element('10', '00100', 'Выполнено', [
                'requestNumber' => '100',
                'supportingDocFiles' => [['id' => 7, 'url' => $secretUrl]],
                'reportFiles' => [],
            ], [['VALUE' => $secretUrl]]),
            $this->element('11', '101', 'Заявка в работе', [
                'requestNumber' => '101',
                'supportingDocFiles' => [['id' => 8, 'name' => 'Персональное имя.docx']],
            ]),
            $this->element('12', '100', 'Заявка отозвана', ['requestNumber' => '100']),
        ];
        $snapshot = $this->snapshot($elements);

        $report = (new BitrixSnapshotInventory())->inspect($snapshot);

        self::assertTrue($report['snapshot']['integrityVerified']);
        self::assertSame(3, $report['snapshot']['records']);
        self::assertSame(['Выполнено' => 1, 'Заявка в работе' => 1, 'Заявка отозвана' => 1], $report['statuses']);
        self::assertSame('10', $report['numberCandidates']['ID']['minimum']);
        self::assertSame('12', $report['migrationEligibility']['bitrixNumberHighWatermark']);
        self::assertSame(2, $report['migrationEligibility']['terminal']['records']);
        self::assertSame([
            ['number' => '11', 'status' => 'Заявка в работе'],
        ], $report['migrationEligibility']['deferred']['requests']);
        self::assertSame('100', $report['numberCandidates']['NAME']['minimum']);
        self::assertSame('101', $report['numberCandidates']['NAME']['maximum']);
        self::assertSame(['10'], $report['numberCandidates']['NAME']['leadingZeroElementIds']);
        self::assertSame(['10', '12'], $report['numberCandidates']['NAME']['duplicatesAfterNumericNormalization']['100']);
        self::assertSame(2, $report['fileStructures']['DETAIL_TEXT.supportingDocFiles']['totalItems']);
        self::assertSame(1, $report['fileStructures']['PROPERTY_648']['totalItems']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secretUrl, $encoded);
        self::assertStringNotContainsString('Персональное имя', $encoded);
    }

    public function testRejectsSnapshotChangedAfterPublication(): void
    {
        $snapshot = $this->snapshot([$this->element('10', '100', 'Выполнено')]);
        file_put_contents($snapshot . '/elements.jsonl', "{}\n", FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        (new BitrixSnapshotInventory())->inspect($snapshot);
    }

    /** @param array<string, mixed> $detailOverrides
     *  @param mixed $property648
     *  @return array<string, mixed>
     */
    private function element(
        string $id,
        string $name,
        string $status,
        array $detailOverrides = [],
        mixed $property648 = null,
    ): array {
        $element = [
            'ID' => $id,
            'NAME' => $name,
            'DETAIL_TEXT' => json_encode([
                'status' => $status,
                ...$detailOverrides,
            ], JSON_THROW_ON_ERROR),
        ];
        if ($property648 !== null) {
            $element['PROPERTY_648'] = $property648;
        }
        return $element;
    }

    /** @param list<array<string, mixed>> $elements */
    private function snapshot(array $elements): string
    {
        $transport = new class ($elements) implements BitrixTransport {
            /** @var list<array<string, mixed>> */
            private readonly array $elements;

            /** @param list<array<string, mixed>> $elements */
            public function __construct(array $elements)
            {
                $this->elements = $elements;
            }

            public function call(string $method, array $parameters = []): array
            {
                return $method === 'lists.field.get'
                    ? ['result' => ['PROPERTY_648' => ['NAME' => 'Документы']]]
                    : ['result' => $this->elements];
            }
        };
        $snapshot = $this->directory . '/snapshot';
        (new BitrixSnapshotExporter())->export(
            new BitrixListClient($transport, 'lists', 114, 0),
            new BitrixUserClient($transport),
            $snapshot,
            'lists',
            114,
        );
        return $snapshot;
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
