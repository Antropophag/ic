<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ai;

use App\Application\Ai\TechnicalSpecificationCandidate;
use App\Application\Ai\TechnicalSpecificationSelector;
use PHPUnit\Framework\TestCase;

final class TechnicalSpecificationSelectorTest extends TestCase
{
    public function testSelectsOnlySupportedLikelyTechnicalSpecification(): void
    {
        $selected = (new TechnicalSpecificationSelector())->select([
            $this->candidate(1, 'Протокол.pdf', 'application/pdf'),
            $this->candidate(2, 'ТЗ на закупку.pdf', 'application/pdf'),
            $this->candidate(3, 'техзадание.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ]);

        self::assertSame([2], array_map(static fn (TechnicalSpecificationCandidate $item): int => $item->versionId, $selected));
    }

    public function testReturnsSeveralCandidatesInNameAndFormatPriority(): void
    {
        $selected = (new TechnicalSpecificationSelector())->select([
            $this->candidate(1, 'ТЗ.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            $this->candidate(2, 'Техническое задание.pdf', 'application/pdf'),
        ]);

        self::assertSame([2, 1], array_map(static fn (TechnicalSpecificationCandidate $item): int => $item->versionId, $selected));
    }

    private function candidate(int $id, string $name, string $mime): TechnicalSpecificationCandidate
    {
        return new TechnicalSpecificationCandidate($id, $name, $mime, 1);
    }
}
