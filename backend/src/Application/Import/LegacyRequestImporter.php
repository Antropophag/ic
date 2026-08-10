<?php

declare(strict_types=1);

namespace App\Application\Import;

use Throwable;

final class LegacyRequestImporter
{
    public function __construct(private readonly LegacyRequestMapper $mapper)
    {
    }

    /**
     * @param iterable<array<string, mixed>> $elements
     * @return array{records: int, valid: int, invalid: int, created: int, skipped: int, deferred: int, comments: int, commentFiles: int, invalidReasons: array<string, int>}
     */
    public function import(iterable $elements, int $listId, ?LegacyRequestWriter $writer = null): array
    {
        $summary = ['records' => 0, 'valid' => 0, 'invalid' => 0, 'created' => 0, 'skipped' => 0,
            'deferred' => 0, 'comments' => 0, 'commentFiles' => 0, 'invalidReasons' => []];

        foreach ($elements as $element) {
            ++$summary['records'];
            if (!$this->terminalElement($element)) {
                ++$summary['deferred'];
                continue;
            }
            try {
                $request = $this->mapper->map($element, $listId);
            } catch (Throwable $error) {
                ++$summary['invalid'];
                $reason = preg_replace('/\b\d+\b/u', '{id}', $error->getMessage()) ?? $error->getMessage();
                $summary['invalidReasons'][$reason] = ($summary['invalidReasons'][$reason] ?? 0) + 1;
                continue;
            }

            ++$summary['valid'];
            $summary['comments'] += count($request->comments);
            $summary['commentFiles'] += array_sum(array_map(
                static fn (LegacyCommentData $comment): int => $comment->fileCount,
                $request->comments,
            ));
            if ($writer === null) {
                continue;
            }

            $outcome = $writer->write($request);
            ++$summary[$outcome === LegacyImportOutcome::Created ? 'created' : 'skipped'];
        }

        ksort($summary['invalidReasons']);
        return $summary;
    }

    /** @param array<string, mixed> $element */
    private function terminalElement(array $element): bool
    {
        try {
            $details = json_decode((string) ($element['DETAIL_TEXT'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return true; // The mapper reports malformed source data as invalid.
        }
        $status = is_array($details) ? ($details['status'] ?? null) : null;
        return !in_array($status, [
            'Заявка зарегистрирована', 'Заявка в работе', 'В работе', 'Работы приостановлены',
            'Приостановлено', 'Подготовка заключения', 'Контроль СБ', 'Черновик',
        ], true);
    }
}
