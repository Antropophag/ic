<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

final class RequestStatusScope
{
    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @param list<string>|string|null $status
     */
    public function apply(array &$where, array &$params, string|array|null $status): void
    {
        $statuses = is_array($status) ? $status : ($status === null ? [] : [$status]);
        if ($statuses === []) {
            return;
        }
        $placeholders = [];
        foreach ($statuses as $index => $filteredStatus) {
            $placeholder = ":filter_status_{$index}";
            $placeholders[] = $placeholder;
            $params[$placeholder] = $filteredStatus;
        }
        $where[] = 'r.status IN (' . implode(', ', $placeholders) . ')';
    }
}
