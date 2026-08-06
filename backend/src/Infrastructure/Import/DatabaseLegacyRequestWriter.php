<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Application\Import\LegacyImportOutcome;
use App\Application\Import\LegacyRequestData;
use App\Application\Import\LegacyRequestWriter;
use App\Infrastructure\Clock;
use yii\db\Connection;
use yii\db\IntegrityException;

final class DatabaseLegacyRequestWriter implements LegacyRequestWriter
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function write(LegacyRequestData $request): LegacyImportOutcome
    {
        $transaction = $this->db->beginTransaction();
        try {
            $existing = $this->db->createCommand(
                'SELECT id FROM {{%requests}} WHERE legacy_id = :legacy_id FOR UPDATE',
                [':legacy_id' => $request->legacyId],
            )->queryScalar();
            if ($existing !== false) {
                $transaction->commit();
                return LegacyImportOutcome::Skipped;
            }

            $initiatorId = $this->initiatorId($request);
            $this->db->createCommand(
                'UPDATE {{%request_number_sequence}} '
                . 'SET value = GREATEST(value, :number) WHERE id = 1',
                [':number' => $request->number],
            )->execute();
            $createdAt = $request->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

            $this->db->createCommand()->insert('{{%requests}}', [
                'number' => $request->number,
                'legacy_id' => $request->legacyId,
                'initiator_id' => $initiatorId,
                'department_name' => $request->department !== '' ? $request->department : null,
                'department_external_id' => $request->departmentExternalId,
                'department_source' => $request->department !== '' || $request->departmentExternalId !== null
                    ? 'bitrix24'
                    : 'unknown',
                'status' => $request->status->value,
                'product_name' => $request->productName,
                'manufacturer' => $request->manufacturer,
                'supplier' => $request->supplier,
                'sample_quantity' => $request->sampleQuantity,
                'test_method' => $request->testMethod,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->execute();
            $requestId = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%request_transitions}}', [
                'request_id' => $requestId,
                'actor_id' => $initiatorId,
                'from_status' => null,
                'to_status' => $request->status->value,
                'action' => 'import',
                'rule_id' => 'IMP-002',
                'created_at' => $createdAt,
            ])->execute();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.imported',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $initiatorId,
                'rule_id' => 'IMP-001',
                'payload_json' => ['legacyId' => $request->legacyId],
                'created_at' => Clock::now(),
            ])->execute();
            $transaction->commit();
            return LegacyImportOutcome::Created;
        } catch (IntegrityException $error) {
            $transaction->rollBack();
            if ($this->legacyRequestExists($request->legacyId)) {
                return LegacyImportOutcome::Skipped;
            }
            throw $error;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function legacyRequestExists(string $legacyId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%requests}} WHERE legacy_id = :legacy_id',
            [':legacy_id' => $legacyId],
        )->queryScalar() !== false;
    }

    private function initiatorId(LegacyRequestData $request): int
    {
        $login = $request->creator->adLogin;
        $now = Clock::now();
        $existing = $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE ad_login = :login FOR UPDATE',
            [':login' => $login],
        )->queryScalar();
        $created = $existing === false;
        if ($created) {
            $this->db->createCommand()->insert('{{%users}}', [
                'ad_login' => $login,
                'display_name' => $request->creator->displayName,
                'email' => $request->creator->email,
                'position' => $request->creator->position,
                'department' => $request->department !== '' ? $request->department : null,
                'is_active' => $request->creator->active,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            $userId = (int) $this->db->getLastInsertID();
        } else {
            // Existing local identity wins: import must not activate, deactivate,
            // or overwrite a profile that may already have been synchronized from AD.
            $userId = (int) $existing;
        }
        if ($created && $request->creator->active) {
            $roleId = $this->db->createCommand("SELECT id FROM {{%roles}} WHERE code = 'employee'")->queryScalar();
            if ($roleId !== false) {
                $this->db->createCommand()->upsert('{{%user_roles}}', [
                    'user_id' => $userId,
                    'role_id' => (int) $roleId,
                    'created_at' => $now,
                ], false)->execute();
            }
        }
        return $userId;
    }
}
