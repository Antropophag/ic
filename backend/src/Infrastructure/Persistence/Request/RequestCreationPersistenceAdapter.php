<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Command\CreateRequestCommand;
use App\Application\Request\CreationContext;
use App\Application\Request\Port\RequestCreationGateway;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class RequestCreationPersistenceAdapter implements RequestCreationGateway
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

    public function creationContext(int $initiatorId): CreationContext
    {
        $codes = $this->db->createCommand(
            'SELECT r.code FROM {{%roles}} r '
            . 'JOIN {{%user_roles}} ur ON ur.role_id = r.id WHERE ur.user_id = :user_id',
            [':user_id' => $initiatorId],
        )->queryColumn();
        $roles = array_values(array_filter(array_map(
            static fn (string $code): ?Role => Role::tryFrom($code),
            $codes,
        )));
        $active = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $initiatorId],
        )->queryScalar() !== false;
        return new CreationContext($roles, $active);
    }

    public function departmentSnapshotForUpdate(int $initiatorId): ?string
    {
        $department = $this->db->createCommand(
            'SELECT NULLIF(TRIM(department), \'\') FROM {{%users}} WHERE id = :id FOR UPDATE',
            [':id' => $initiatorId],
        )->queryScalar();
        return $department === false ? null : $department;
    }

    public function allocateNumber(): int
    {
        // MariaDB locks the single counter row and LAST_INSERT_ID keeps the allocated value connection-local.
        $this->db->createCommand(
            'UPDATE {{%request_number_sequence}} SET value = LAST_INSERT_ID(value + 1) WHERE id = 1',
        )->execute();
        return (int) $this->db->createCommand('SELECT LAST_INSERT_ID()')->queryScalar();
    }

    public function creationTimestamp(): string
    {
        return Clock::now();
    }

    public function insertRequest(
        CreateRequestCommand $command,
        string $department,
        int $number,
        string $createdAt,
    ): int {
        $this->db->createCommand()->insert('{{%requests}}', [
            'number' => $number,
            'initiator_id' => $command->initiatorId,
            'department_name' => $department,
            'department_source' => 'current_profile',
            'status' => RequestStatus::Registered->value,
            'product_name' => $command->productName,
            'manufacturer' => $command->manufacturer,
            'supplier' => $command->supplier,
            'sample_quantity' => $command->sampleQuantity,
            'test_method' => $command->testMethod,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function recordCreation(int $requestId, int $actorId, string $createdAt): void
    {
        $this->db->createCommand()->insert('{{%request_transitions}}', [
            'request_id' => $requestId,
            'actor_id' => $actorId,
            'from_status' => null,
            'to_status' => RequestStatus::Registered->value,
            'action' => 'create',
            'rule_id' => 'REQ-007',
            'created_at' => $createdAt,
        ])->execute();
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.created',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => 'REQ-007',
            'payload_json' => ['to_status' => RequestStatus::Registered->value],
            'created_at' => $createdAt,
        ])->execute();
    }

    public function enqueueCreationNotifications(
        int $requestId,
        int $number,
        string $productName,
        int $initiatorId,
    ): void {
        $outbox = new NotificationOutbox($this->db);
        foreach ($this->activeManagers() as $manager) {
            $outbox->enqueue(
                $requestId,
                'request.created',
                $manager['email'],
                $manager['name'],
                sprintf('Новая заявка №%06d зарегистрирована', $number),
                sprintf(
                    "Зарегистрирована новая заявка №%06d на проведение испытаний.\n"
                    . "Объект испытаний: %s.\n\n"
                    . 'Откройте реестр заявок в портале, чтобы назначить исполнителя.',
                    $number,
                    $productName,
                ),
            );
        }
        $initiator = $this->userContact($initiatorId);
        if ($initiator !== null) {
            $outbox->enqueue(
                $requestId,
                'request.created',
                $initiator['email'],
                $initiator['name'],
                sprintf('Заявка №%06d зарегистрирована', $number),
                sprintf(
                    "Ваша заявка №%06d на проведение испытаний зарегистрирована.\n"
                    . "Объект испытаний: %s.\n\n"
                    . 'Мы сообщим, когда испытательный центр назначит исполнителя.',
                    $number,
                    $productName,
                ),
            );
        }
    }

    public function createdRequest(int $requestId): array
    {
        return $this->db->createCommand(
            'SELECT id, number, legacy_id, initiator_id, status, product_name, manufacturer, supplier, '
            . 'sample_quantity, legacy_sample_quantity_raw, test_method, revision, lock_version, color, '
            . 'department_name AS department, created_at, updated_at FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
    }

    public function recordRejectedCreation(int $actorId, string $ruleId): void
    {
        $active = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $actorId],
        )->queryScalar() !== false;
        if (
            !$active
            && $this->db->createCommand(
                'SELECT 1 FROM {{%users}} WHERE id = :id',
                [':id' => $actorId],
            )->queryScalar() === false
        ) {
            return;
        }
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.create_denied',
            'entity_type' => 'request_creation',
            'entity_id' => $actorId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
    }

    /** @return list<array{email: string, name: string}> */
    private function activeManagers(): array
    {
        return $this->db->createCommand(
            'SELECT DISTINCT TRIM(u.email) AS email, u.display_name AS name FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != '' "
            . 'AND r.code IN (:role0,:role1)',
            [':role0' => 'ic_manager', ':role1' => 'laboratory_manager'],
        )->queryAll();
    }

    /** @return array{email: string, name: string}|null */
    private function userContact(int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT TRIM(email) AS email, display_name AS name FROM {{%users}} '
            . "WHERE id = :id AND is_active = 1 AND email IS NOT NULL AND TRIM(email) != ''",
            [':id' => $userId],
        )->queryOne();
        return $row === false ? null : $row;
    }
}
