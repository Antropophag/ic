<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import;

use App\Application\Import\LegacyImportOutcome;
use App\Application\Import\LegacyRequestData;
use App\Application\Import\LegacyRequestImporter;
use App\Application\Import\LegacyRequestMapper;
use App\Application\Import\LegacyUserData;
use App\Application\Import\LegacyRequestWriter;
use PHPUnit\Framework\TestCase;

final class LegacyRequestImporterTest extends TestCase
{
    public function testDryRunValidatesWithoutWriting(): void
    {
        $summary = (new LegacyRequestImporter($this->mapper()))->import([
            $this->element('1'),
            ['ID' => '2', 'DETAIL_TEXT' => '{}'],
        ], 114);

        self::assertSame([
            'records' => 2,
            'valid' => 1,
            'invalid' => 1,
            'created' => 0,
            'skipped' => 0,
            'deferred' => 0,
            'comments' => 0,
            'commentFiles' => 0,
            'invalidReasons' => ['Legacy creator ID is required.' => 1],
        ], $summary);
    }

    public function testDefersNonTerminalRequestsWithoutWriting(): void
    {
        $writer = new class () implements LegacyRequestWriter {
            public int $calls = 0;
            public function write(LegacyRequestData $request): LegacyImportOutcome
            {
                ++$this->calls;
                return LegacyImportOutcome::Created;
            }
        };
        $element = $this->element('3');
        $details = json_decode($element['DETAIL_TEXT'], true, 512, JSON_THROW_ON_ERROR);
        $details['status'] = 'В работе';
        $element['DETAIL_TEXT'] = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $summary = (new LegacyRequestImporter($this->mapper()))->import([$element], 114, $writer);

        self::assertSame(1, $summary['deferred']);
        self::assertSame(0, $writer->calls);
    }

    public function testApplyCountsCreatedAndIdempotentlySkippedRecords(): void
    {
        $writer = new class () implements LegacyRequestWriter {
            public int $calls = 0;

            public function write(LegacyRequestData $request): LegacyImportOutcome
            {
                return ++$this->calls === 1 ? LegacyImportOutcome::Created : LegacyImportOutcome::Skipped;
            }
        };

        $summary = (new LegacyRequestImporter($this->mapper()))->import([
            $this->element('1'),
            $this->element('2'),
        ], 114, $writer);

        self::assertSame(2, $writer->calls);
        self::assertSame(1, $summary['created']);
        self::assertSame(1, $summary['skipped']);
    }

    /** @return array<string, string> */
    private function element(string $id): array
    {
        return [
            'ID' => $id,
            'DETAIL_TEXT' => json_encode([
                'nameType' => 'Образец',
                'manufacturer' => 'Производитель',
                'supplier' => 'Поставщик',
                'countTestItems' => '2 шт',
                'testMethod' => 'Метод',
                'status' => 'Выполнено',
                'dateCreate' => '2024-01-01T12:00:00+03:00',
                'creator' => ['ID' => '1595', 'NAME' => 'Иван', 'LAST_NAME' => 'Иванов'],
                'department' => ['NAME' => 'Лаборатория'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function mapper(): LegacyRequestMapper
    {
        return new LegacyRequestMapper([
            '1595' => new LegacyUserData(
                '1595',
                'ivanov',
                'Иванов Иван',
                'ivanov@example.test',
                null,
                true,
            ),
        ]);
    }
}
