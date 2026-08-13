<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Application\Request\ChangeRequestDepartmentResult;
use App\Application\Request\DepartmentChangeSnapshot;
use App\Infrastructure\Clock;

trait RequestDepartmentPersistence
{
    public function lockAdministratorRole(int $actorId): bool
    {
        return $this->db->createCommand(
            'SELECT ur.role_id FROM {{%user_roles}} ur '
            . 'JOIN {{%roles}} role ON role.id = ur.role_id '
            . "WHERE ur.user_id = :actor_id AND role.code = 'administrator' FOR UPDATE",
            [':actor_id' => $actorId],
        )->queryScalar() !== false;
    }

    public function departmentChangeSnapshotForUpdate(
        int $requestId,
        int $actorId,
    ): ?DepartmentChangeSnapshot {
        $row = $this->db->createCommand(
            'SELECT r.department_name, r.department_external_id, r.lock_version, actor.is_active '
            . 'FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id '
            . 'WHERE r.id = :request_id FOR UPDATE',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryOne();
        if ($row === false) {
            return null;
        }

        return new DepartmentChangeSnapshot(
            (string) $row['department_name'],
            $row['department_external_id'] === null ? null : (string) $row['department_external_id'],
            (int) $row['lock_version'],
            (bool) $row['is_active'],
        );
    }

    public function departmentChangeTimestamp(): string
    {
        return Clock::now();
    }

    public function persistDepartmentChange(
        int $requestId,
        string $department,
        int $lockVersion,
        string $changedAt,
    ): void {
        $this->db->createCommand()->update('{{%requests}}', [
            'department_name' => $department,
            'department_external_id' => null,
            'department_source' => 'manual',
            'lock_version' => $lockVersion,
            'updated_at' => $changedAt,
        ], ['id' => $requestId])->execute();
    }

    public function recordDepartmentChanged(
        int $requestId,
        int $actorId,
        DepartmentChangeSnapshot $previous,
        string $department,
        string $ruleId,
        string $changedAt,
    ): void {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.department_changed',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [
                'old_department_name' => $previous->departmentName,
                'new_department_name' => $department,
                'old_department_external_id' => $previous->departmentExternalId,
                'new_department_external_id' => null,
                'source' => 'manual',
            ],
            'created_at' => $changedAt,
        ])->execute();
    }

    public function departmentChangeResult(int $requestId): ChangeRequestDepartmentResult
    {
        return new ChangeRequestDepartmentResult($this->findOne($requestId));
    }
}
