<?php

declare(strict_types=1);

namespace App\Application\Ai;

final class TechnicalSpecificationSelector
{
    /** @param list<TechnicalSpecificationCandidate> $documents
     * @return list<TechnicalSpecificationCandidate>
     */
    public function select(array $documents): array
    {
        $result = array_values(array_filter($documents, fn (TechnicalSpecificationCandidate $document): bool =>
            $this->score($document->name) > 0 && in_array($document->mimeType, [
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ], true)));
        usort($result, fn (TechnicalSpecificationCandidate $left, TechnicalSpecificationCandidate $right): int =>
            $this->score($right->name) <=> $this->score($left->name)
            ?: ($right->mimeType === 'application/pdf') <=> ($left->mimeType === 'application/pdf')
            ?: $right->version <=> $left->version);
        return $result;
    }

    private function score(string $name): int
    {
        $normalized = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));
        if (str_contains($normalized, 'техническое задание')) {
            return 4;
        }
        if (str_contains($normalized, 'техзадание')) {
            return 3;
        }
        if (preg_match('/(^|[^а-яё])тз(?:[^а-яё]|$)/u', $normalized) === 1) {
            return str_contains($normalized, 'закуп') ? 4 : 2;
        }
        return 0;
    }
}
