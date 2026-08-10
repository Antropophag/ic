<?php

declare(strict_types=1);

namespace App\Application\Import;

use RuntimeException;

final class LegacyCounterpartyOverrides
{
    private const HEADER = ['legacy_id', 'manufacturer', 'supplier', 'source', 'reason'];

    /** @param array<string, array{manufacturer?: string, supplier?: string}> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @param iterable<array<string, mixed>> $elements */
    public static function load(iterable $elements, int $listId, ?string $path = null): self
    {
        $path ??= dirname(__DIR__, 3) . '/resources/migration/bitrix24-counterparty-overrides.csv';
        $rows = self::rows($path);
        $overrides = [];
        foreach ($rows as $line => $row) {
            [$legacyId, $manufacturer, $supplier, $source, $reason] = $row;
            if (isset($overrides[$legacyId])) {
                throw new RuntimeException("Duplicate legacy_id in counterparty overrides at line {$line}: {$legacyId}.");
            }
            $values = [];
            foreach (['manufacturer' => $manufacturer, 'supplier' => $supplier] as $field => $value) {
                if ($value === '') {
                    continue;
                }
                if (trim($value) === '' || trim($value) !== $value) {
                    throw new RuntimeException("Counterparty override {$field} at line {$line} contains invalid whitespace.");
                }
                self::assertMaximumLength($value, 500, "counterparty override {$field} at line {$line}");
                $values[$field] = $value;
            }
            if ($values === []) {
                throw new RuntimeException("Counterparty override at line {$line} must define manufacturer or supplier.");
            }
            if (
                !in_array($source, [
                    'legacy_comment',
                    'legacy_comment_and_approved_missing_value',
                    'approved_missing_value',
                ], true) || trim($reason) === ''
            ) {
                throw new RuntimeException("Invalid counterparty override provenance at line {$line}.");
            }
            $overrides[$legacyId] = $values;
        }

        self::validateSnapshot($elements, $listId, $overrides);
        return new self($overrides);
    }

    /** @return array<string, array{manufacturer?: string, supplier?: string}> */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @param iterable<array<string, mixed>> $elements
     * @param array<string, array{manufacturer?: string, supplier?: string}> $overrides
     */
    private static function validateSnapshot(iterable $elements, int $listId, array $overrides): void
    {
        $found = [];
        foreach ($elements as $element) {
            $id = is_string($element['ID'] ?? null) ? $element['ID'] : '';
            $legacyId = "bitrix24:{$listId}:{$id}";
            if (!isset($overrides[$legacyId])) {
                continue;
            }
            $details = json_decode((string) ($element['DETAIL_TEXT'] ?? ''), true);
            if (!is_array($details)) {
                throw new RuntimeException("Counterparty override source is not a valid request: {$legacyId}.");
            }
            foreach ($overrides[$legacyId] as $field => $_value) {
                $sourceValue = is_string($details[$field] ?? null) ? trim($details[$field]) : '';
                if ($sourceValue !== '') {
                    throw new RuntimeException("Counterparty override would replace a non-empty {$field}: {$legacyId}.");
                }
            }
            $found[$legacyId] = true;
        }
        foreach ($overrides as $legacyId => $_values) {
            if (!isset($found[$legacyId])) {
                throw new RuntimeException("Counterparty override legacy_id does not exist in the snapshot: {$legacyId}.");
            }
        }
    }

    /** @return array<int, list<string>> */
    private static function rows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open counterparty overrides: {$path}.");
        }
        try {
            if (fgetcsv($handle, escape: '') !== self::HEADER) {
                throw new RuntimeException("Unexpected counterparty override header: {$path}.");
            }
            $rows = [];
            $line = 1;
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                ++$line;
                if (count($row) !== count(self::HEADER)) {
                    throw new RuntimeException("Invalid counterparty override row at {$path}:{$line}.");
                }
                $rows[$line] = $row;
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private static function assertMaximumLength(string $value, int $maximum, string $field): void
    {
        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters > $maximum) {
            throw new RuntimeException("{$field} exceeds {$maximum} characters.");
        }
    }
}
