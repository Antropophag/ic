<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use yii\db\Connection;

final class OperationalSummaryQuery
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{
     *   active: int,
     *   unassigned: int,
     *   ready_to_start: int,
     *   in_progress: int,
     *   suspended: int,
     *   expertise: int,
     *   security_review: int,
     *   directions: list<array<string, mixed>>
     * }
     */
    public function find(int $actorId): array
    {
        $totals = $this->db->createCommand(
            "SELECT SUM(r.status IN ('registered', 'in_progress', 'suspended')) AS active, "
            . "SUM(r.status = 'registered' AND current_executor.user_id IS NULL) AS unassigned, "
            . "SUM(r.status = 'registered' AND current_executor.user_id IS NOT NULL) AS ready_to_start, "
            . "SUM(r.status = 'in_progress') AS in_progress, "
            . "SUM(r.status = 'suspended') AS suspended, "
            . "SUM(r.status = 'opinion_preparation') AS expertise, "
            . "SUM(r.status = 'security_review') AS security_review "
            . 'FROM {{%requests}} r '
            . 'LEFT JOIN {{%request_assignments}} current_executor '
            . "ON current_executor.request_id = r.id AND current_executor.assignment_type = 'executor' "
            . "AND current_executor.valid_to IS NULL WHERE r.status IN "
            . "('registered', 'in_progress', 'suspended', 'opinion_preparation', 'security_review')",
        )->queryOne();

        return [
            'active' => (int) ($totals['active'] ?? 0),
            'unassigned' => (int) ($totals['unassigned'] ?? 0),
            'ready_to_start' => (int) ($totals['ready_to_start'] ?? 0),
            'in_progress' => (int) ($totals['in_progress'] ?? 0),
            'suspended' => (int) ($totals['suspended'] ?? 0),
            'expertise' => (int) ($totals['expertise'] ?? 0),
            'security_review' => (int) ($totals['security_review'] ?? 0),
            'directions' => $this->directions($this->canViewExecutors($actorId)),
        ];
    }


    /** @return list<array<string, mixed>> */
    private function directions(bool $includeExecutors): array
    {
        /** @var array<string, array{id: string, title: string, color: string, active: int, unassigned: int, executors: list<array{user_id: int|null, display_name: string, is_available: bool, active: int, in_progress: int, suspended: int}>}> $directions */
        $directions = [
            'metrology' => ['id' => 'metrology', 'title' => 'Метрологические испытания', 'color' => 'goldenrod', 'active' => 0, 'unassigned' => 0, 'executors' => []],
            'mechanical' => ['id' => 'mechanical', 'title' => 'Механические испытания', 'color' => 'blue', 'active' => 0, 'unassigned' => 0, 'executors' => []],
            'electrical' => ['id' => 'electrical', 'title' => 'Электротехнические испытания', 'color' => 'violet', 'active' => 0, 'unassigned' => 0, 'executors' => []],
            'unclassified' => ['id' => 'unclassified', 'title' => 'Без направления', 'color' => 'neutral', 'active' => 0, 'unassigned' => 0, 'executors' => []],
        ];
        $rows = $this->db->createCommand($includeExecutors
            ? (
            'SELECT r.color, assignment.user_id, assignee.display_name, '
            . '(assignee.is_active = 1 AND EXISTS(SELECT 1 FROM {{%user_roles}} assignee_role '
            . 'JOIN {{%roles}} role ON role.id = assignee_role.role_id '
            . "WHERE assignee_role.user_id = assignee.id AND role.code = 'ic_executor')) AS is_available, "
            . 'COUNT(*) AS active, '
            . "SUM(r.status = 'in_progress') AS in_progress, SUM(r.status = 'suspended') AS suspended, "
            . 'SUM(assignment.user_id IS NULL) AS unassigned FROM {{%requests}} r '
            . 'LEFT JOIN {{%request_assignments}} assignment ON assignment.request_id = r.id '
            . "AND assignment.assignment_type = 'executor' AND assignment.valid_to IS NULL "
            . 'LEFT JOIN {{%users}} assignee ON assignee.id = assignment.user_id '
            . "WHERE r.status IN ('registered', 'in_progress', 'suspended') "
            . 'GROUP BY r.color, assignment.user_id, assignee.display_name, is_available '
            . 'ORDER BY assignee.display_name, assignment.user_id'
            )
            : (
                'SELECT r.color, NULL AS user_id, NULL AS display_name, 0 AS is_available, COUNT(*) AS active, '
                . "SUM(r.status = 'in_progress') AS in_progress, SUM(r.status = 'suspended') AS suspended, "
                . 'SUM(assignment.user_id IS NULL) AS unassigned '
                . 'FROM {{%requests}} r '
                . 'LEFT JOIN {{%request_assignments}} assignment ON assignment.request_id = r.id '
                . "AND assignment.assignment_type = 'executor' AND assignment.valid_to IS NULL "
                . "WHERE r.status IN ('registered', 'in_progress', 'suspended') GROUP BY r.color"
            ))->queryAll();
        foreach ($rows as $row) {
            $directionId = match ($row['color']) {
                'blue' => 'mechanical', 'orange' => 'metrology', 'violet' => 'electrical', default => 'unclassified',
            };
            $directions[$directionId]['active'] += (int) $row['active'];
            $directions[$directionId]['unassigned'] += (int) $row['unassigned'];
            if ($row['user_id'] === null) {
                continue;
            }
            $available = (int) $row['is_available'] === 1;
            foreach ($directions[$directionId]['executors'] as &$executor) {
                $sameExecutor = $available
                    ? $executor['user_id'] === (int) $row['user_id']
                    : !$executor['is_available'];
                if ($sameExecutor) {
                    $executor['active'] += (int) $row['active'];
                    $executor['in_progress'] += (int) $row['in_progress'];
                    $executor['suspended'] += (int) $row['suspended'];
                    continue 2;
                }
            }
            unset($executor);
            $directions[$directionId]['executors'][] = [
                'user_id' => $available ? (int) $row['user_id'] : null,
                'display_name' => $available ? (string) $row['display_name'] : 'Недоступный исполнитель',
                'is_available' => $available,
                'active' => (int) $row['active'],
                'in_progress' => (int) $row['in_progress'],
                'suspended' => (int) $row['suspended'],
            ];
        }
        return array_values($directions);
    }

    private function canViewExecutors(int $actorId): bool
    {
        return (bool) $this->db->createCommand(
            'SELECT EXISTS(SELECT 1 FROM {{%user_roles}} user_role '
            . 'JOIN {{%roles}} role ON role.id = user_role.role_id '
            . "WHERE user_role.user_id = :actor AND role.code IN ('ic_manager', 'laboratory_manager'))",
            [':actor' => $actorId],
        )->queryScalar();
    }
}
