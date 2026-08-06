<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Infrastructure\Clock;
use yii\db\Connection;
use yii\db\IntegrityException;

final class AdministratorBootstrap
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_MICROSECONDS = 50_000;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<string> $adLogins
     * @return array{usersCreated: int, rolesAssigned: int}
     */
    public function bootstrap(array $adLogins): array
    {
        $enableLogging = $this->db->enableLogging;
        $enableProfiling = $this->db->enableProfiling;
        $this->db->enableLogging = false;
        $this->db->enableProfiling = false;

        try {
            $adLogins = $this->normalize($adLogins);
            if ($adLogins === []) {
                $hasActiveAdministrator = $this->db->createCommand(
                    'SELECT 1 FROM {{%users}} u '
                    . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
                    . 'JOIN {{%roles}} r ON r.id = ur.role_id '
                    . "WHERE u.is_active = 1 AND r.code = 'administrator' LIMIT 1",
                )->queryScalar() !== false;
                if (!$hasActiveAdministrator) {
                    throw new \RuntimeException(
                        'No active local administrator exists; configure BOOTSTRAP_ADMIN_AD_LOGINS.',
                    );
                }

                return ['usersCreated' => 0, 'rolesAssigned' => 0];
            }

            // SELECT ... FOR UPDATE cannot lock a row that does not exist. If
            // a concurrent first login or deployment inserts the same user or
            // role, retry the complete idempotent operation after that
            // transaction has committed.
            for ($attempt = 1; true; ++$attempt) {
                try {
                    return $this->bootstrapOnce($adLogins);
                } catch (\Throwable $error) {
                    if (
                        $attempt >= self::MAX_ATTEMPTS
                        || !$this->isRetryableConcurrencyError($error)
                    ) {
                        throw $error;
                    }

                    usleep(self::RETRY_DELAY_MICROSECONDS);
                }
            }
        } finally {
            $this->db->enableLogging = $enableLogging;
            $this->db->enableProfiling = $enableProfiling;
        }
    }

    private function isRetryableConcurrencyError(\Throwable $error): bool
    {
        if ($error instanceof IntegrityException) {
            $sqlState = (string) ($error->errorInfo[0] ?? '');
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            return $sqlState === '23000' && $driverCode === 1062;
        }

        if (!$error instanceof \yii\db\Exception) {
            return false;
        }

        $sqlState = (string) ($error->errorInfo[0] ?? '');
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
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

            foreach ($adLogins as $index => $adLogin) {
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
                            sprintf(
                                'Bootstrap administrator at position %d is locally disabled; refusing to reactivate it.',
                                $index + 1,
                            ),
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
        $trimmed = array_map(static fn (string $adLogin): string => trim($adLogin), $adLogins);

        foreach ($trimmed as $index => $adLogin) {
            if ($adLogin === '') {
                throw new \InvalidArgumentException(
                    sprintf('Invalid empty AD login at position %d.', $index + 1),
                );
            }

            $adLogin = strtolower($adLogin);
            if (strlen($adLogin) > 128 || preg_match('/^[a-z0-9._-]+$/', $adLogin) !== 1) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid sAMAccountName at position %d.', $index + 1),
                );
            }
            $normalized[$adLogin] = true;
        }

        return array_keys($normalized);
    }
}
