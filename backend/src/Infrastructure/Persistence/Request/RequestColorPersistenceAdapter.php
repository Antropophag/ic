<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Port\RequestColorGateway;
use App\Domain\Request\RequestColor;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use yii\db\Connection;

final readonly class RequestColorPersistenceAdapter implements RequestColorGateway
{
    public function __construct(private Connection $db)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $transaction = $this->db->beginTransaction();
        try {
            $result = $operation();
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function lockVersionForUpdate(int $requestId): ?int
    {
        $lockVersion = $this->db->createCommand(
            'SELECT lock_version FROM {{%requests}} WHERE id = :id FOR UPDATE',
            [':id' => $requestId],
        )->queryScalar();
        return $lockVersion === false ? null : (int) $lockVersion;
    }

    /** @return list<Role> */
    public function rolesFor(int $actorId): array
    {
        $codes = $this->db->createCommand(
            'SELECT r.code FROM {{%roles}} r '
            . 'JOIN {{%user_roles}} ur ON ur.role_id = r.id WHERE ur.user_id = :user_id',
            [':user_id' => $actorId],
        )->queryColumn();

        return array_values(array_filter(array_map(
            static fn (string $code): ?Role => Role::tryFrom($code),
            $codes,
        )));
    }

    public function isActiveUser(int $actorId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $actorId],
        )->queryScalar() !== false;
    }

    public function persistColorChange(int $requestId, RequestColor $color, int $lockVersion): void
    {
        $this->db->createCommand()->update('{{%requests}}', [
            'color' => $color->value,
            'lock_version' => $lockVersion,
            'updated_at' => Clock::now(),
        ], ['id' => $requestId])->execute();
    }

    public function recordColorMarked(int $requestId, int $actorId, RequestColor $color, string $ruleId): void
    {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.color_marked',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['color' => $color->value],
            'created_at' => Clock::now(),
        ])->execute();
    }

    public function recordRejectedColor(int $requestId, int $actorId, string $ruleId): void
    {
        $actorExists = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id',
            [':id' => $actorId],
        )->queryScalar();
        if ($actorExists === false) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.color_mark_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
    }
}
