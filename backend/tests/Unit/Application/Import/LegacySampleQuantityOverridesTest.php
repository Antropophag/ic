<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import;

use App\Application\Import\LegacySampleQuantityOverrides;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacySampleQuantityOverridesTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
    }

    public function testLoadsVerifiedOverrideAndExcludesOtherReviewStatuses(): void
    {
        $overrides = $this->load(
            $this->reviewRows(),
            [['bitrix24:114:10', '6', 'verified_nxm', '2 positions × 3 each']],
        )->quantities();

        self::assertSame(['bitrix24:114:10' => 6], $overrides);
    }

    public function testRejectsInvalidOverrideQuantity(): void
    {
        $this->expectExceptionMessage('override sample_quantity at line 2 must be an integer greater than or equal to 1');
        $this->load(
            $this->reviewRows(),
            [['bitrix24:114:10', '0', 'verified_nxm', 'reviewed']],
        );
    }

    public function testRejectsDuplicateOverrideLegacyId(): void
    {
        $this->expectExceptionMessage('Duplicate legacy_id in sample quantity overrides');
        $row = ['bitrix24:114:10', '6', 'verified_nxm', 'reviewed'];
        $this->load($this->reviewRows(), [$row, $row]);
    }

    public function testRejectsReviewedLegacyIdMissingFromSnapshot(): void
    {
        $review = $this->reviewRows();
        $review[] = ['bitrix24:114:99', 'AMBIGUOUS', '4', '2', '', 'missing source row'];

        $this->expectExceptionMessage('Reviewed legacy_id does not exist in the snapshot: bitrix24:114:99');
        $this->load($review, [['bitrix24:114:10', '6', 'verified_nxm', 'reviewed']]);
    }

    public function testRejectsOverrideNotEqualToSnapshotMultiplication(): void
    {
        $this->expectExceptionMessage('Override sample_quantity does not equal snapshot N × M');
        $this->load(
            $this->reviewRows(),
            [['bitrix24:114:10', '5', 'verified_nxm', 'incorrect product']],
        );
    }

    public function testRejectsAmbiguousRowInOverrides(): void
    {
        $this->expectExceptionMessage('Override is not VERIFIED in the review');
        $this->load(
            $this->reviewRows(),
            [
                ['bitrix24:114:10', '6', 'verified_nxm', 'reviewed'],
                ['bitrix24:114:11', '6', 'verified_nxm', 'must stay unknown'],
            ],
        );
    }

    /**
     * @param list<list<string>> $reviewRows
     * @param list<list<string>> $overrideRows
     */
    private function load(array $reviewRows, array $overrideRows): LegacySampleQuantityOverrides
    {
        $review = $this->csv(
            ['legacy_id', 'status', 'positions', 'units_per_position', 'sample_quantity', 'reason'],
            $reviewRows,
        );
        $overrides = $this->csv(
            ['legacy_id', 'sample_quantity', 'source', 'reason'],
            $overrideRows,
        );
        return LegacySampleQuantityOverrides::load($this->elements(), 114, $overrides, $review);
    }

    /** @return list<list<string>> */
    private function reviewRows(): array
    {
        return [
            ['bitrix24:114:10', 'VERIFIED', '2', '3', '6', 'card agrees'],
            ['bitrix24:114:11', 'AMBIGUOUS', '2', '3', '', 'cannot verify card'],
            ['bitrix24:114:12', 'CONFLICT', '2', '3', '', 'card contradicts N'],
        ];
    }

    /** @return list<array<string, string>> */
    private function elements(): array
    {
        $elements = [];
        foreach ([10, 11, 12] as $id) {
            $elements[] = [
                'ID' => (string) $id,
                'DETAIL_TEXT' => json_encode([
                    'countTestItems' => 'Количество номенклатурных позиций - 2 шт. По 3 шт. каждой номенклатурной позиции.',
                ], JSON_THROW_ON_ERROR),
            ];
        }
        return $elements;
    }

    /** @param list<string> $header
     *  @param list<list<string>> $rows
     */
    private function csv(array $header, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ic-quantity-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary CSV file.');
        }
        $this->temporaryFiles[] = $path;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write a temporary CSV file.');
        }
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
        return $path;
    }
}
