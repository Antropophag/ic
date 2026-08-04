<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Infrastructure\Clock;
use yii\db\Connection;
use yii\db\IntegrityException;

final class BreakGlassIdentityProvisioner
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_MICROSECONDS = 50_000;

    public function __construct(
        private readonly Connection $db,
        private readonly BreakGlassConfiguration $configuration,
    ) {
    }

    /** @return array{enabled: bool, userCreated: bool, roleAssigned: bool, rolesRemoved: int} */
    public function provision(): array
    {
        if ($this->configuration->isDisabled()) {
            return ['enabled' => false, 'userCreated' => false, 'roleAssigned' => false, 'rolesRemoved' => 0];
        }
        if (!$this->configuration->isValid()) {
            throw new \RuntimeException('Break-glass configuration is incomplete or invalid.');
        }

        for ($attempt = 1; true; ++$attempt) {
            try {
                return $this->provisionOnce();
            } catch (\Throwable $error) {
                if ($attempt >= self::MAX_ATTEMPTS || !$this->isRetryableConcurrencyError($error)) {
                    throw $error;
                }
                usleep(self::RETRY_DELAY_MICROSECONDS);
            }
        }
    }

    /** @return array{enabled: true, userCreated: bool, roleAssigned: bool, rolesRemoved: int} */
    private function provisionOnce(): array
    {
        $roleId = $this->db->createCommand(
            "SELECT id FROM {{%roles}} WHERE code = 'administrator'",
        )->queryScalar();
        if ($roleId === false) {
            throw new \RuntimeException('Administrator role is missing; apply access migrations first.');
        }

        $transaction = $this->db->beginTransaction();
        try {
            $user = $this->db->createCommand(
                'SELECT id, display_name, position FROM {{%users}} WHERE ad_login = :login FOR UPDATE',
                [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
            )->queryOne();
            $now = Clock::now();
            $userCreated = false;
            if ($user === false) {
                $this->db->createCommand()->insert('{{%users}}', [
                    'ad_login' => BreakGlassAuthenticator::TECHNICAL_LOGIN,
                    'display_name' => 'Аварийный администратор',
                    'position' => 'Техническая учётная запись',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->execute();
                $userId = (int) $this->db->getLastInsertID();
                $userCreated = true;
            } else {
                if (
                    (string) $user['display_name'] !== 'Аварийный администратор'
                    || (string) $user['position'] !== 'Техническая учётная запись'
                ) {
                    throw new \RuntimeException('Reserved break-glass identity login is already in use.');
                }
                $userId = (int) $user['id'];
            }

            $assigned = $this->db->createCommand(
                'SELECT 1 FROM {{%user_roles}} WHERE user_id = :user_id AND role_id = :role_id',
                [':user_id' => $userId, ':role_id' => (int) $roleId],
            )->queryScalar() !== false;
            if (!$assigned) {
                $this->db->createCommand()->insert('{{%user_roles}}', [
                    'user_id' => $userId,
                    'role_id' => (int) $roleId,
                    'assigned_by' => null,
                    'created_at' => $now,
                ])->execute();
            }

            $rolesRemoved = $this->db->createCommand()->delete(
                '{{%user_roles}}',
                '[[user_id]] = :user_id AND [[role_id]] <> :role_id',
                [':user_id' => $userId, ':role_id' => (int) $roleId],
            )->execute();

            $transaction->commit();
            return [
                'enabled' => true,
                'userCreated' => $userCreated,
                'roleAssigned' => !$assigned,
                'rolesRemoved' => $rolesRemoved,
            ];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function isRetryableConcurrencyError(\Throwable $error): bool
    {
        if ($error instanceof IntegrityException) {
            return (string) ($error->errorInfo[0] ?? '') === '23000'
                && (int) ($error->errorInfo[1] ?? 0) === 1062;
        }
        if (!$error instanceof \yii\db\Exception) {
            return false;
        }

        return (string) ($error->errorInfo[0] ?? '') === '40001'
            || in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true);
    }
}
