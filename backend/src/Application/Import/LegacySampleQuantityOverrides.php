<?php

declare(strict_types=1);

namespace App\Application\Import;

use RuntimeException;

final class LegacySampleQuantityOverrides
{
    private const REVIEW_HEADER = [
        'legacy_id', 'status', 'positions', 'units_per_position', 'sample_quantity', 'reason',
    ];
    private const OVERRIDE_HEADER = ['legacy_id', 'sample_quantity', 'source', 'reason'];
    private const NXM_PATTERN = '/^\s*Количество номенклатурных позиций\s*[-—]\s*(\d+)\s*шт\.\s*'
        . 'По\s*(\d+)\s*шт\.\s*каждой номенклатурной позиции\.\s*$/ui';

    /** @param array<string, int> $quantities */
    private function __construct(private readonly array $quantities)
    {
    }

    /**
     * @param iterable<array<string, mixed>> $elements
     */
    public static function load(
        iterable $elements,
        int $listId,
        ?string $overridePath = null,
        ?string $reviewPath = null,
    ): self {
        $resourceDirectory = dirname(__DIR__, 3) . '/resources/migration';
        $overridePath ??= $resourceDirectory . '/bitrix24-sample-quantity-overrides.csv';
        $reviewPath ??= $resourceDirectory . '/bitrix24-sample-quantity-review.csv';

        $review = self::review($reviewPath);
        $overrides = self::overrides($overridePath, $review);
        self::validateSnapshot($elements, $listId, $review, $overrides);

        return new self(array_map(
            static fn (array $override): int => $override['quantity'],
            $overrides,
        ));
    }

    /** @return array<string, int> */
    public function quantities(): array
    {
        return $this->quantities;
    }

    /** @return array<string, array{status: string, positions: int, units: int}> */
    private static function review(string $path): array
    {
        $rows = self::rows($path, self::REVIEW_HEADER);
        $review = [];
        foreach ($rows as $line => $row) {
            [$legacyId, $status, $positions, $units, $quantity] = $row;
            if (isset($review[$legacyId])) {
                throw new RuntimeException("Duplicate legacy_id in review at line {$line}: {$legacyId}.");
            }
            if (!in_array($status, ['VERIFIED', 'AMBIGUOUS', 'CONFLICT'], true)) {
                throw new RuntimeException("Invalid review status at line {$line}: {$status}.");
            }
            $positionsValue = self::positiveInteger($positions, "review positions at line {$line}");
            $unitsValue = self::positiveInteger($units, "review units_per_position at line {$line}");
            if ($status === 'VERIFIED') {
                $quantityValue = self::positiveInteger($quantity, "review sample_quantity at line {$line}");
                if ($quantityValue !== $positionsValue * $unitsValue) {
                    throw new RuntimeException("Review sample_quantity does not equal N × M at line {$line}.");
                }
            } elseif ($quantity !== '') {
                throw new RuntimeException("Non-VERIFIED review row must not contain sample_quantity at line {$line}.");
            }
            $review[$legacyId] = ['status' => $status, 'positions' => $positionsValue, 'units' => $unitsValue];
        }
        return $review;
    }

    /**
     * @param array<string, array{status: string, positions: int, units: int}> $review
     * @return array<string, array{quantity: int}>
     */
    private static function overrides(string $path, array $review): array
    {
        $rows = self::rows($path, self::OVERRIDE_HEADER);
        $overrides = [];
        foreach ($rows as $line => $row) {
            [$legacyId, $quantity, $source, $reason] = $row;
            if (isset($overrides[$legacyId])) {
                throw new RuntimeException("Duplicate legacy_id in sample quantity overrides at line {$line}: {$legacyId}.");
            }
            $quantityValue = self::positiveInteger($quantity, "override sample_quantity at line {$line}");
            if ($source !== 'verified_nxm' || trim($reason) === '') {
                throw new RuntimeException("Invalid override provenance at line {$line}.");
            }
            if (($review[$legacyId]['status'] ?? null) !== 'VERIFIED') {
                throw new RuntimeException("Override is not VERIFIED in the review at line {$line}: {$legacyId}.");
            }
            $overrides[$legacyId] = ['quantity' => $quantityValue];
        }

        foreach ($review as $legacyId => $entry) {
            if ($entry['status'] === 'VERIFIED' && !isset($overrides[$legacyId])) {
                throw new RuntimeException("VERIFIED review row is missing from overrides: {$legacyId}.");
            }
        }
        return $overrides;
    }

    /**
     * @param iterable<array<string, mixed>> $elements
     * @param array<string, array{status: string, positions: int, units: int}> $review
     * @param array<string, array{quantity: int}> $overrides
     */
    private static function validateSnapshot(iterable $elements, int $listId, array $review, array $overrides): void
    {
        $found = [];
        foreach ($elements as $element) {
            $id = is_string($element['ID'] ?? null) ? $element['ID'] : '';
            $legacyId = "bitrix24:{$listId}:{$id}";
            $details = json_decode((string) ($element['DETAIL_TEXT'] ?? ''), true);
            $raw = is_array($details) && is_string($details['countTestItems'] ?? null)
                ? $details['countTestItems']
                : null;
            if ($raw === null || preg_match(self::NXM_PATTERN, $raw, $matches) !== 1) {
                if (isset($review[$legacyId])) {
                    throw new RuntimeException("Reviewed legacy_id does not contain the expected N × M source: {$legacyId}.");
                }
                continue;
            }
            if (!isset($review[$legacyId])) {
                throw new RuntimeException("N × M snapshot row is missing from the controlled review: {$legacyId}.");
            }
            $positions = (int) $matches[1];
            $units = (int) $matches[2];
            $entry = $review[$legacyId];
            if ($entry['positions'] !== $positions || $entry['units'] !== $units) {
                throw new RuntimeException("Review N × M values do not match the snapshot: {$legacyId}.");
            }
            if (isset($overrides[$legacyId]) && $overrides[$legacyId]['quantity'] !== $positions * $units) {
                throw new RuntimeException("Override sample_quantity does not equal snapshot N × M: {$legacyId}.");
            }
            $found[$legacyId] = true;
        }
        foreach ($review as $legacyId => $_entry) {
            if (!isset($found[$legacyId])) {
                throw new RuntimeException("Reviewed legacy_id does not exist in the snapshot: {$legacyId}.");
            }
        }
    }

    /** @param list<string> $expectedHeader
     *  @return array<int, list<string>>
     */
    private static function rows(string $path, array $expectedHeader): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open migration data: {$path}.");
        }
        try {
            $header = fgetcsv($handle, escape: '');
            if ($header !== $expectedHeader) {
                throw new RuntimeException("Unexpected migration data header: {$path}.");
            }
            $rows = [];
            $line = 1;
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                ++$line;
                if (count($row) !== count($expectedHeader)) {
                    throw new RuntimeException("Invalid migration data row at {$path}:{$line}.");
                }
                $rows[$line] = $row;
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private static function positiveInteger(string $value, string $field): int
    {
        if (preg_match('/^[1-9]\d*$/D', $value) !== 1) {
            throw new RuntimeException("{$field} must be an integer greater than or equal to 1.");
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ($integer === false) {
            throw new RuntimeException("{$field} exceeds the supported range.");
        }
        return $integer;
    }
}
