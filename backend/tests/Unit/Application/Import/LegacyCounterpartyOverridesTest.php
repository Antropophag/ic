<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import;

use App\Application\Import\LegacyCounterpartyOverrides;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyCounterpartyOverridesTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
    }

    public function testLoadsExplicitManufacturerWithoutCreatingSupplierOverride(): void
    {
        $values = $this->load([
            ['bitrix24:114:10', 'АО ЩЛЗ (кооперация)', '', 'legacy_comment', 'Direct source comment'],
        ])->values();

        self::assertSame([
            'bitrix24:114:10' => ['manufacturer' => 'АО ЩЛЗ (кооперация)'],
        ], $values);
        self::assertArrayNotHasKey('supplier', $values['bitrix24:114:10']);
    }

    public function testLoadsAllApprovedLegacyCounterpartyDecisions(): void
    {
        $values = $this->load([
            ['bitrix24:114:18312', 'АО ЩЛЗ (кооперация)', 'Поставщик не указан', 'legacy_comment_and_approved_missing_value', 'Controlled legacy decision'],
            ['bitrix24:114:18314', 'Производитель не указан', 'Поставщик не указан', 'approved_missing_value', 'Controlled legacy decision'],
            ['bitrix24:114:22610', '', 'Поставщик не указан', 'approved_missing_value', 'Controlled legacy decision'],
            ['bitrix24:114:29840', '', 'Поставщик не указан', 'approved_missing_value', 'Controlled legacy decision'],
        ], [
            $this->element('18312', ' ', ' '),
            $this->element('18314', ' ', ' '),
            $this->element('22610', 'Известный производитель', ' '),
            $this->element('29840', 'Другой производитель', ' '),
        ])->values();

        self::assertSame([
            'manufacturer' => 'АО ЩЛЗ (кооперация)',
            'supplier' => 'Поставщик не указан',
        ], $values['bitrix24:114:18312']);
        self::assertSame([
            'manufacturer' => 'Производитель не указан',
            'supplier' => 'Поставщик не указан',
        ], $values['bitrix24:114:18314']);
        self::assertSame(['supplier' => 'Поставщик не указан'], $values['bitrix24:114:22610']);
        self::assertSame(['supplier' => 'Поставщик не указан'], $values['bitrix24:114:29840']);
    }

    public function testRejectsWhitespaceOnlyOverride(): void
    {
        $this->expectExceptionMessage('contains invalid whitespace');
        $this->load([
            ['bitrix24:114:10', ' ', '', 'legacy_comment', 'Direct source comment'],
        ]);
    }

    public function testRejectsDuplicateLegacyId(): void
    {
        $this->expectExceptionMessage('Duplicate legacy_id in counterparty overrides');
        $row = ['bitrix24:114:10', 'АО ЩЛЗ', '', 'legacy_comment', 'Direct source comment'];
        $this->load([$row, $row]);
    }

    public function testRejectsUnknownLegacyId(): void
    {
        $this->expectExceptionMessage('does not exist in the snapshot');
        $this->load([
            ['bitrix24:114:99', 'АО ЩЛЗ', '', 'legacy_comment', 'Direct source comment'],
        ]);
    }

    public function testRejectsRowWithoutOverrideValue(): void
    {
        $this->expectExceptionMessage('must define manufacturer or supplier');
        $this->load([
            ['bitrix24:114:10', '', '', 'legacy_comment', 'Direct source comment'],
        ]);
    }

    /**
     * @param list<list<string>> $rows
     * @param list<array<string, string>>|null $elements
     */
    private function load(array $rows, ?array $elements = null): LegacyCounterpartyOverrides
    {
        $path = tempnam(sys_get_temp_dir(), 'ic-counterparty-');
        if ($path === false) {
            throw new RuntimeException('Cannot create temporary CSV.');
        }
        $this->temporaryFiles[] = $path;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write temporary CSV.');
        }
        fputcsv($handle, ['legacy_id', 'manufacturer', 'supplier', 'source', 'reason'], escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);

        return LegacyCounterpartyOverrides::load($elements ?? [$this->element()], 114, $path);
    }

    /** @return array<string, string> */
    private function element(
        string $id = '10',
        string $manufacturer = ' ',
        string $supplier = 'Поставщик',
    ): array {
        return [
            'ID' => $id,
            'DETAIL_TEXT' => json_encode([
                'manufacturer' => $manufacturer,
                'supplier' => $supplier,
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
