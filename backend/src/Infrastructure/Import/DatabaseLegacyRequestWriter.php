<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Application\Import\LegacyImportOutcome;
use App\Application\Import\LegacyRequestData;
use App\Application\Import\LegacyRequestWriter;
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
                . 'SET value = LAST_INSERT_ID(value + 1) WHERE id = 1',
            )->execute();
            $number = (int) $this->db->createCommand('SELECT LAST_INSERT_ID()')->queryScalar();
            $createdAt = $request->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

            $this->db->createCommand()->insert('{{%requests}}', [
                'number' => $number,
                'legacy_id' => $request->legacyId,
                'initiator_id' => $initiatorId,
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
                'payload_json' => json_encode(
                    ['legacyId' => $request->legacyId],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
                'created_at' => gmdate('Y-m-d H:i:s.u'),
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
        $login = 'legacy.bitrix24.' . $request->creatorLegacyId;
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->createCommand()->upsert('{{%users}}', [
            'ad_login' => $login,
            'display_name' => $request->creatorDisplayName !== ''
                ? $request->creatorDisplayName
                : 'Пользователь Bitrix24',
            'department' => $request->department !== '' ? $request->department : null,
            'is_active' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'display_name' => $request->creatorDisplayName !== ''
                ? $request->creatorDisplayName
                : 'Пользователь Bitrix24',
            'department' => $request->department !== '' ? $request->department : null,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE ad_login = :login',
            [':login' => $login],
        )->queryScalar();
    }
}
