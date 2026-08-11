<?php

declare(strict_types=1);

namespace App\Application\Document;

final class DocumentPersonNameFormatter
{
    public function abbreviated(string $displayName): string
    {
        $parts = preg_split('/\s+/u', trim($displayName), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return '';
        }
        $surname = array_shift($parts);
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') . '.';
        }

        return $initials === '' ? $surname : $surname . ' ' . $initials;
    }
}
