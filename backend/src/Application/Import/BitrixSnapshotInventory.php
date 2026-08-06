<?php

declare(strict_types=1);

namespace App\Application\Import;

use JsonException;
use RuntimeException;

final class BitrixSnapshotInventory
{
    /** @return array<string, mixed> */
    public function inspect(string $snapshotDirectory): array
    {
        $snapshotDirectory = rtrim($snapshotDirectory, DIRECTORY_SEPARATOR);
        $manifest = $this->readJsonObject($snapshotDirectory . '/manifest.json');
        if (!in_array($manifest['formatVersion'] ?? null, [1, 2], true) || ($manifest['complete'] ?? null) !== true) {
            throw new RuntimeException('Snapshot manifest is unsupported or incomplete.');
        }

        $snapshotFiles = ['fields.json', 'elements.jsonl'];
        if ($manifest['formatVersion'] === 2) {
            $snapshotFiles[] = 'users.json';
        }
        foreach ($snapshotFiles as $file) {
            $expected = $manifest['files'][$file]['sha256'] ?? null;
            $expectedBytes = $manifest['files'][$file]['bytes'] ?? null;
            $actual = hash_file('sha256', $snapshotDirectory . '/' . $file);
            $actualBytes = filesize($snapshotDirectory . '/' . $file);
            if (
                !is_string($expected)
                || !is_int($expectedBytes)
                || $actual === false
                || $actualBytes === false
                || !hash_equals($expected, $actual)
                || $expectedBytes !== $actualBytes
            ) {
                throw new RuntimeException("Snapshot integrity check failed for {$file}.");
            }
        }

        $fields = $this->readJsonObject($snapshotDirectory . '/fields.json');
        $numberValues = ['ID' => [], 'CODE' => [], 'NAME' => []];
        $statuses = [];
        $terminalStatuses = [];
        $deferredStatuses = [];
        $deferredRequests = [];
        $elementIds = [];
        $duplicateElementIds = [];
        $invalidDetails = [];
        $fileStructures = [
            'DETAIL_TEXT.supportingDocFiles' => $this->emptyStructure(),
            'DETAIL_TEXT.reportFiles' => $this->emptyStructure(),
            'PROPERTY_648' => $this->emptyStructure(),
        ];

        $records = 0;
        foreach ($this->elements($snapshotDirectory . '/elements.jsonl') as $line => $element) {
            ++$records;
            $elementId = $this->scalarString($element['ID'] ?? null);
            if ($elementId === '') {
                $elementId = "line:{$line}";
            } elseif (isset($elementIds[$elementId])) {
                $duplicateElementIds[$elementId] = true;
            }
            $elementIds[$elementId] = true;
            foreach (['ID', 'CODE', 'NAME'] as $candidate) {
                if (array_key_exists($candidate, $element) && is_scalar($element[$candidate])) {
                    $numberValues[$candidate][] = [$elementId, trim((string) $element[$candidate])];
                }
            }
            $this->observeStructure($fileStructures['PROPERTY_648'], $element['PROPERTY_648'] ?? null, array_key_exists('PROPERTY_648', $element));

            $rawDetails = $element['DETAIL_TEXT'] ?? null;
            if (!is_string($rawDetails)) {
                $invalidDetails[] = $elementId;
                continue;
            }
            try {
                $details = json_decode($rawDetails, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $details = null;
            }
            if (!is_array($details)) {
                $invalidDetails[] = $elementId;
                continue;
            }

            $status = $this->scalarString($details['status'] ?? null);
            $statuses[$status !== '' ? $status : '(empty)'] = ($statuses[$status !== '' ? $status : '(empty)'] ?? 0) + 1;
            if ($this->isTerminalStatus($status)) {
                $terminalStatuses[$status] = ($terminalStatuses[$status] ?? 0) + 1;
            } else {
                $reportedStatus = $status !== '' ? $status : '(empty)';
                $deferredStatuses[$reportedStatus] = ($deferredStatuses[$reportedStatus] ?? 0) + 1;
                $deferredRequests[] = ['number' => $elementId, 'status' => $reportedStatus];
            }
            foreach ($details as $key => $value) {
                if (is_string($key) && $this->isNumberCandidate($key) && is_scalar($value)) {
                    $path = "DETAIL_TEXT.{$key}";
                    $numberValues[$path][] = [$elementId, trim((string) $value)];
                }
            }
            $this->observeStructure(
                $fileStructures['DETAIL_TEXT.supportingDocFiles'],
                $details['supportingDocFiles'] ?? null,
                array_key_exists('supportingDocFiles', $details),
            );
            $this->observeStructure(
                $fileStructures['DETAIL_TEXT.reportFiles'],
                $details['reportFiles'] ?? null,
                array_key_exists('reportFiles', $details),
            );
        }

        if (($manifest['records'] ?? null) !== $records) {
            throw new RuntimeException('Snapshot record count does not match its manifest.');
        }

        $numberCandidates = [];
        foreach ($numberValues as $path => $values) {
            $numberCandidates[$path] = $this->numberStatistics($records, $values);
        }
        ksort($numberCandidates);
        ksort($statuses);
        ksort($terminalStatuses);
        ksort($deferredStatuses);
        foreach ($fileStructures as &$structure) {
            ksort($structure['valueTypes']);
            ksort($structure['itemTypes']);
            ksort($structure['itemKeySets']);
        }
        unset($structure);

        return [
            'formatVersion' => 1,
            'snapshot' => [
                'source' => $manifest['source'] ?? null,
                'startedAt' => $manifest['startedAt'] ?? null,
                'completedAt' => $manifest['completedAt'] ?? null,
                'pages' => $manifest['pages'] ?? null,
                'records' => $records,
                'sourceTotal' => $manifest['sourceTotal'] ?? null,
                'integrityVerified' => true,
            ],
            'fieldDefinitions' => [
                'count' => count($fields),
                'propertyCodes' => $this->propertyCodes($fields),
            ],
            'elementIds' => [
                'unique' => count($elementIds),
                'duplicates' => array_keys($duplicateElementIds),
            ],
            'details' => ['invalidElementIds' => $invalidDetails],
            'statuses' => $statuses,
            'migrationEligibility' => [
                'authoritativeNumberField' => 'ID',
                'bitrixNumberHighWatermark' => $numberCandidates['ID']['maximum'],
                'terminal' => [
                    'records' => array_sum($terminalStatuses),
                    'statuses' => $terminalStatuses,
                ],
                'deferred' => [
                    'records' => count($deferredRequests),
                    'statuses' => $deferredStatuses,
                    'requests' => $deferredRequests,
                ],
            ],
            'numberCandidates' => $numberCandidates,
            'fileStructures' => $fileStructures,
            'privacy' => 'The report contains field names, statuses, source element IDs, and numeric number candidates; field values and file URLs are omitted.',
        ];
    }

    /** @return iterable<int, array<string, mixed>> */
    private function elements(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open snapshot elements.');
        }
        try {
            $line = 0;
            while (($raw = fgets($handle)) !== false) {
                ++$line;
                try {
                    $element = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException("Invalid JSON on snapshot line {$line}.", 0, $exception);
                }
                if (!is_array($element)) {
                    throw new RuntimeException("Snapshot line {$line} is not an object.");
                }
                yield $line => $element;
            }
            if (!feof($handle)) {
                throw new RuntimeException('Cannot finish reading snapshot elements.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    private function readJsonObject(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$path}.", 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException("JSON value in {$path} is not an object.");
        }
        return $value;
    }

    /** @param array<string, mixed> $fields
     *  @return list<string>
     */
    private function propertyCodes(array $fields): array
    {
        $codes = [];
        foreach (array_keys($fields) as $key) {
            if (str_starts_with($key, 'PROPERTY_')) {
                $codes[] = $key;
            }
        }
        sort($codes);
        return $codes;
    }

    private function isNumberCandidate(string $key): bool
    {
        return preg_match('/(?:number|номер|^num$|request[_-]?(?:no|id))/iu', $key) === 1;
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            'Выполнено',
            'Заявка выполнена',
            'Отказано',
            'В проведении испытаний отказано',
            'Заявка отозвана',
        ], true);
    }

    /** @return array{present: int, totalItems: int, valueTypes: array<string, int>, itemTypes: array<string, int>, itemKeySets: array<string, int>} */
    private function emptyStructure(): array
    {
        return ['present' => 0, 'totalItems' => 0, 'valueTypes' => [], 'itemTypes' => [], 'itemKeySets' => []];
    }

    /** @param array{present: int, totalItems: int, valueTypes: array<string, int>, itemTypes: array<string, int>, itemKeySets: array<string, int>} $summary */
    private function observeStructure(array &$summary, mixed $value, bool $present): void
    {
        if (!$present) {
            return;
        }
        ++$summary['present'];
        $type = $this->valueType($value);
        $summary['valueTypes'][$type] = ($summary['valueTypes'][$type] ?? 0) + 1;
        if (!is_array($value)) {
            return;
        }
        $summary['totalItems'] += count($value);
        foreach ($value as $item) {
            $itemType = $this->valueType($item);
            $summary['itemTypes'][$itemType] = ($summary['itemTypes'][$itemType] ?? 0) + 1;
            if (is_array($item)) {
                $keys = array_map('strval', array_keys($item));
                sort($keys);
                $keySet = implode(',', $keys);
                $summary['itemKeySets'][$keySet] = ($summary['itemKeySets'][$keySet] ?? 0) + 1;
            }
        }
    }

    private function valueType(mixed $value): string
    {
        if (!is_array($value)) {
            return get_debug_type($value);
        }
        return array_is_list($value) ? 'list' : 'object';
    }

    /** @param list<array{string, string}> $values
     *  @return array<string, mixed>
     */
    private function numberStatistics(int $records, array $values): array
    {
        $empty = 0;
        $nonNumericElementIds = [];
        $leadingZeroElementIds = [];
        $numeric = [];
        foreach ($values as [$elementId, $value]) {
            if ($value === '') {
                ++$empty;
                continue;
            }
            if (preg_match('/^\d+$/D', $value) !== 1) {
                $nonNumericElementIds[] = $elementId;
                continue;
            }
            if (strlen($value) > 1 && $value[0] === '0') {
                $leadingZeroElementIds[] = $elementId;
            }
            $normalized = ltrim($value, '0');
            $normalized = $normalized !== '' ? $normalized : '0';
            $numeric[$normalized][] = $elementId;
        }

        $numbers = array_map('strval', array_keys($numeric));
        usort($numbers, $this->compareDecimals(...));
        $duplicates = [];
        foreach ($numeric as $number => $ids) {
            if (count($ids) > 1) {
                $duplicates[$number] = $ids;
            }
        }

        return [
            'records' => $records,
            'present' => count($values),
            'missing' => $records - count($values),
            'empty' => $empty,
            'numeric' => array_sum(array_map('count', $numeric)),
            'nonNumeric' => count($nonNumericElementIds),
            'nonNumericElementIds' => array_slice($nonNumericElementIds, 0, 100),
            'nonNumericElementIdsTruncated' => count($nonNumericElementIds) > 100,
            'leadingZeroElementIds' => $leadingZeroElementIds,
            'minimum' => $numbers[0] ?? null,
            'maximum' => $numbers !== [] ? $numbers[array_key_last($numbers)] : null,
            'duplicatesAfterNumericNormalization' => $duplicates,
            'gaps' => $this->gapRanges($numbers),
        ];
    }

    private function compareDecimals(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    /** @param list<string> $numbers
     *  @return array{available: bool, count?: int, ranges?: list<array{int, int}>, reason?: string}
     */
    private function gapRanges(array $numbers): array
    {
        if ($numbers === []) {
            return ['available' => true, 'count' => 0, 'ranges' => []];
        }
        $maximum = $numbers[array_key_last($numbers)];
        if ($this->compareDecimals($maximum, (string) PHP_INT_MAX) > 0) {
            return ['available' => false, 'reason' => 'maximum exceeds the platform integer range'];
        }
        $minimumInteger = (int) $numbers[0];
        $maximumInteger = (int) $maximum;
        if ($maximumInteger - $minimumInteger > 1_000_000) {
            return ['available' => false, 'reason' => 'candidate range exceeds 1000000'];
        }

        $present = array_fill_keys(array_map('intval', $numbers), true);
        $ranges = [];
        $count = 0;
        $start = null;
        for ($number = $minimumInteger;; ++$number) {
            if (!isset($present[$number])) {
                $start ??= $number;
                ++$count;
            } elseif ($start !== null) {
                $ranges[] = [$start, $number - 1];
                $start = null;
            }
            if ($number === $maximumInteger) {
                break;
            }
        }
        if ($start !== null) {
            $ranges[] = [$start, $maximumInteger];
        }
        return ['available' => true, 'count' => $count, 'ranges' => $ranges];
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
