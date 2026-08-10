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
        self::assertSame(0, $report['numberCandidates']['NAME']['gaps']['count']);
        self::assertSame(0, $report['numberCandidates']['ID']['gaps']['count']);
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

    public function testDoesNotEnumerateAnExcessiveCandidateRange(): void
    {
        $snapshot = $this->snapshot([
            $this->element('1', '1', 'Выполнено'),
            $this->element('1000002', '1000002', 'Выполнено'),
        ]);

        $report = (new BitrixSnapshotInventory())->inspect($snapshot);

        self::assertFalse($report['numberCandidates']['ID']['gaps']['available']);
        self::assertSame('candidate range exceeds 1000000', $report['numberCandidates']['ID']['gaps']['reason']);
    }

    public function testReportsSparseIdentifierRanges(): void
    {
        $snapshot = $this->snapshot([
            $this->element('10', '10', 'Выполнено'),
            $this->element('12', '12', 'Выполнено'),
        ]);

        $gaps = (new BitrixSnapshotInventory())->inspect($snapshot)['numberCandidates']['ID']['gaps'];

        self::assertSame(1, $gaps['count']);
        self::assertSame([[11, 11]], $gaps['ranges']);
    }

    public function testReportsMalformedDetailsAndNumberEdgeCasesWithoutLeakingValues(): void
    {
        $snapshot = $this->snapshot([$this->element('10', '10', 'Выполнено')]);
        $elements = [
            ['ID' => '', 'NAME' => '', 'PROPERTY_648' => 'not-a-list', 'DETAIL_TEXT' => null],
            ['ID' => '20', 'NAME' => 'not-a-number', 'DETAIL_TEXT' => '{'],
            ['ID' => '20', 'NAME' => '9223372036854775808', 'DETAIL_TEXT' => json_encode([
                'status' => '',
                'requestNumber' => 'invalid',
                'supportingDocFiles' => 'not-a-list',
                'reportFiles' => [['id' => 1]],
            ], JSON_THROW_ON_ERROR)],
        ];
        $this->replaceSnapshotFile(
            $snapshot,
            'elements.jsonl',
            implode('', array_map(static fn (array $element): string => json_encode($element, JSON_THROW_ON_ERROR) . "\n", $elements)),
            count($elements),
        );

        $report = (new BitrixSnapshotInventory())->inspect($snapshot);

        self::assertSame(['line:1', '20'], $report['details']['invalidElementIds']);
        self::assertSame([20], $report['elementIds']['duplicates']);
        self::assertSame(1, $report['numberCandidates']['NAME']['empty']);
        self::assertSame(1, $report['numberCandidates']['NAME']['nonNumeric']);
        self::assertFalse($report['numberCandidates']['NAME']['gaps']['available']);
        self::assertSame('maximum exceeds the platform integer range', $report['numberCandidates']['NAME']['gaps']['reason']);
        self::assertSame(1, $report['migrationEligibility']['deferred']['records']);
    }

    public function testRejectsUnsupportedManifest(): void
    {
        $snapshot = $this->snapshot([$this->element('10', '10', 'Выполнено')]);
        $manifest = json_decode((string) file_get_contents($snapshot . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['complete'] = false;
        file_put_contents($snapshot . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('unsupported or incomplete');
        (new BitrixSnapshotInventory())->inspect($snapshot);
    }

    public function testRejectsInvalidElementJsonAfterIntegrityVerification(): void
    {
        $snapshot = $this->snapshot([$this->element('10', '10', 'Выполнено')]);
        $this->replaceSnapshotFile($snapshot, 'elements.jsonl', "{\n", 1);

        $this->expectExceptionMessage('Invalid JSON on snapshot line 1');
        (new BitrixSnapshotInventory())->inspect($snapshot);
    }

    public function testRejectsNonObjectElementAfterIntegrityVerification(): void
    {
        $snapshot = $this->snapshot([$this->element('10', '10', 'Выполнено')]);
        $this->replaceSnapshotFile($snapshot, 'elements.jsonl', "null\n", 1);

        $this->expectExceptionMessage('Snapshot line 1 is not an object');
        (new BitrixSnapshotInventory())->inspect($snapshot);
    }

    public function testRejectsInvalidFieldsJsonAfterIntegrityVerification(): void
    {
        $snapshot = $this->snapshot([$this->element('10', '10', 'Выполнено')]);
        $this->replaceSnapshotFile($snapshot, 'fields.json', '{', 1);

        $this->expectExceptionMessage('Invalid JSON in');
        (new BitrixSnapshotInventory())->inspect($snapshot);
    }

    public function testRejectsNonObjectFieldsJsonAfterIntegrityVerification(): void
    {
        $snapshot = $this->snapshot([$this->element('10', '10', 'Выполнено')]);
        $this->replaceSnapshotFile($snapshot, 'fields.json', 'null', 1);

        $this->expectExceptionMessage('is not an object');
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

    private function replaceSnapshotFile(string $snapshot, string $file, string $contents, int $records): void
    {
        file_put_contents($snapshot . '/' . $file, $contents);
        $manifestPath = $snapshot . '/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['records'] = $records;
        $manifest['files'][$file] = [
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
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
