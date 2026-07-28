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
     * @return array{records: int, valid: int, invalid: int, created: int, skipped: int}
     */
    public function import(iterable $elements, int $listId, ?LegacyRequestWriter $writer = null): array
    {
        $summary = ['records' => 0, 'valid' => 0, 'invalid' => 0, 'created' => 0, 'skipped' => 0];

        foreach ($elements as $element) {
            ++$summary['records'];
            try {
                $request = $this->mapper->map($element, $listId);
            } catch (Throwable) {
                ++$summary['invalid'];
                continue;
            }

            ++$summary['valid'];
            if ($writer === null) {
                continue;
            }

            $outcome = $writer->write($request);
            ++$summary[$outcome === LegacyImportOutcome::Created ? 'created' : 'skipped'];
        }

        return $summary;
    }
}
