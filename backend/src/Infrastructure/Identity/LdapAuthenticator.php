<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Infrastructure\Clock;
use App\Infrastructure\Ldap\LdapClient;
use App\Infrastructure\Ldap\LdapProfile;
use yii\db\Connection;
use yii\db\IntegrityException;

final class LdapAuthenticator
{
    public function __construct(
        private readonly Connection $db,
        private readonly LdapClient $ldap,
    ) {
    }

    /** @return array{id: int, displayName: string} */
    public function authenticate(string $login, string $password): array
    {
        $profile = $this->ldap->authenticate($login, $password);
        if ($profile === null) {
            throw new AuthenticationDenied();
        }

        try {
            return $this->syncProfile($profile);
        } catch (IntegrityException $error) {
            // AUTH-002: SELECT ... FOR UPDATE не сериализует вставку ещё не
            // существующей строки — два параллельных первых входа одной
            // учётки (двойной submit, два устройства) могут оба увидеть
            // отсутствие строки, и один INSERT неизбежно упадёт на unique
            // constraint ad_login. Повторная попытка находит уже
            // созданную конкурентом строку и продолжает как обычный
            // повторный вход.
            return $this->syncProfile($profile);
        }
    }

    /** @return array{id: int, displayName: string} */
    private function syncProfile(LdapProfile $profile): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $existing = $this->db->createCommand(
                'SELECT id, is_active, display_name FROM {{%users}} WHERE ad_login = :login FOR UPDATE',
                [':login' => $profile->login],
            )->queryOne();
            $now = Clock::now();

            if ($existing === false) {
                // AUTH-002: первый вход создаёт локальный профиль с базовой
                // ролью «Сотрудник» — специальные роли назначаются отдельно
                // и не выдаются автоматически из LDAP.
                $this->db->createCommand()->insert('{{%users}}', [
                    'ad_login' => $profile->login,
                    'display_name' => $profile->displayName,
                    'email' => $profile->email,
                    'position' => $profile->position,
                    'department' => $profile->department,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->execute();
                $userId = (int) $this->db->getLastInsertID();
                $roleId = $this->db->createCommand(
                    "SELECT id FROM {{%roles}} WHERE code = 'employee'",
                )->queryScalar();
                if ($roleId !== false) {
                    $this->db->createCommand()->insert('{{%user_roles}}', [
                        'user_id' => $userId,
                        'role_id' => (int) $roleId,
                        'created_at' => $now,
                    ])->execute();
                }
                $displayName = $profile->displayName;
            } else {
                $userId = (int) $existing['id'];
                if (!(bool) $existing['is_active']) {
                    throw new AccountDisabled();
                }
                // AUTH-004: обновляются только атрибуты профиля из AD —
                // is_active и роли остаются под локальным управлением и
                // синхронизацией не затрагиваются.
                $this->db->createCommand()->update('{{%users}}', [
                    'display_name' => $profile->displayName,
                    'email' => $profile->email,
                    'position' => $profile->position,
                    'department' => $profile->department,
                    'updated_at' => $now,
                ], ['id' => $userId])->execute();
                $displayName = $profile->displayName;
            }

            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return ['id' => $userId, 'displayName' => $displayName];
    }
}
