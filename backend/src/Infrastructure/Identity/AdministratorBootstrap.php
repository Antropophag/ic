<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Infrastructure\Clock;
use yii\db\Connection;
use yii\db\IntegrityException;

final class AdministratorBootstrap
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<string> $adLogins
     * @return array{usersCreated: int, rolesAssigned: int}
     */
    public function bootstrap(array $adLogins): array
    {
        $adLogins = $this->normalize($adLogins);
        if ($adLogins === []) {
            return ['usersCreated' => 0, 'rolesAssigned' => 0];
        }

        // SELECT ... FOR UPDATE cannot lock a row that does not exist. If a
        // concurrent first login or deployment inserts the same user/role,
        // retry the complete idempotent operation after that transaction has
        // committed.
        try {
            return $this->bootstrapOnce($adLogins);
        } catch (\Throwable $error) {
            if (!$this->isRetryableConcurrencyError($error)) {
                throw $error;
            }

            return $this->bootstrapOnce($adLogins);
        }
    }

    private function isRetryableConcurrencyError(\Throwable $error): bool
    {
        if ($error instanceof IntegrityException) {
            return true;
        }

        return $error instanceof \yii\db\Exception
            && in_array((string) ($error->errorInfo[0] ?? ''), ['40001', '1213'], true);
    }

    /**
     * @param list<string> $adLogins
     * @return array{usersCreated: int, rolesAssigned: int}
     */
    private function bootstrapOnce(array $adLogins): array
    {

        $roleRows = $this->db->createCommand(
            "SELECT id, code FROM {{%roles}} WHERE code IN ('employee', 'administrator')",
        )->queryAll();
        $roleIds = array_column($roleRows, 'id', 'code');
        if (!isset($roleIds['employee'], $roleIds['administrator'])) {
            throw new \RuntimeException('Required employee and administrator roles are not available; run migrations first.');
        }

        $transaction = $this->db->beginTransaction();
        try {
            $usersCreated = 0;
            $rolesAssigned = 0;
            $now = Clock::now();

            foreach ($adLogins as $adLogin) {
                $user = $this->db->createCommand(
                    'SELECT id, is_active FROM {{%users}} WHERE ad_login = :ad_login FOR UPDATE',
                    [':ad_login' => $adLogin],
                )->queryOne();

                if ($user === false) {
                    $this->db->createCommand()->insert('{{%users}}', [
                        'ad_login' => $adLogin,
                        // LDAP replaces this placeholder with the real profile
                        // on the first successful login.
                        'display_name' => $adLogin,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->execute();
                    $userId = (int) $this->db->getLastInsertID();
                    ++$usersCreated;
                } else {
                    if (!(bool) $user['is_active']) {
                        throw new \RuntimeException(
                            "Bootstrap administrator '{$adLogin}' is locally disabled; refusing to reactivate it.",
                        );
                    }
                    $userId = (int) $user['id'];
                }

                foreach (['employee', 'administrator'] as $roleCode) {
                    $alreadyAssigned = $this->db->createCommand(
                        'SELECT 1 FROM {{%user_roles}} WHERE user_id = :user_id AND role_id = :role_id',
                        [':user_id' => $userId, ':role_id' => (int) $roleIds[$roleCode]],
                    )->queryScalar() !== false;
                    if ($alreadyAssigned) {
                        continue;
                    }

                    $this->db->createCommand()->insert('{{%user_roles}}', [
                        'user_id' => $userId,
                        'role_id' => (int) $roleIds[$roleCode],
                        // There is no authenticated actor during deployment.
                        'assigned_by' => null,
                        'created_at' => $now,
                    ])->execute();
                    ++$rolesAssigned;
                }
            }

            $transaction->commit();
            return ['usersCreated' => $usersCreated, 'rolesAssigned' => $rolesAssigned];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    /**
     * @param list<string> $adLogins
     * @return list<string>
     */
    private function normalize(array $adLogins): array
    {
        $normalized = [];
        foreach ($adLogins as $adLogin) {
            $adLogin = strtolower(trim($adLogin));
            if ($adLogin === '') {
                continue;
            }
            if (strlen($adLogin) > 128 || preg_match('/^[a-z0-9._-]+$/', $adLogin) !== 1) {
                throw new \InvalidArgumentException("Invalid sAMAccountName '{$adLogin}'.");
            }
            $normalized[$adLogin] = true;
        }

        return array_keys($normalized);
    }
}
