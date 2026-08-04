<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Infrastructure\Clock;
use Yii;
use yii\db\Connection;

final class BreakGlassAuthenticator
{
    public const TECHNICAL_LOGIN = '__break_glass__';
    private const MAX_FAILURES_PER_IP = 5;
    private const MAX_FAILURES_GLOBAL = 20;
    private const FAILURE_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly Connection $db,
        private readonly BreakGlassConfiguration $configuration,
    ) {
    }

    public function handles(string $login): bool
    {
        return $this->configuration->matches($login);
    }

    public function reportInvalidConfiguration(string $ip, string $userAgent): void
    {
        $errorCode = $this->configuration->errorCode();
        if ($errorCode === null) {
            return;
        }

        Yii::error([
            'event' => 'authentication.break_glass_configuration_error',
            'reason' => $errorCode,
        ], 'security');
        $identity = $this->identity();
        if ($identity !== null) {
            $this->record(
                'authentication.break_glass_configuration_error',
                $identity['id'],
                $ip,
                $userAgent,
                ['reason' => $errorCode],
            );
        }
    }

    /** @return array{id: int, displayName: string} */
    public function authenticate(string $login, string $password, string $ip, string $userAgent): array
    {
        if (!$this->handles($login)) {
            throw new AuthenticationDenied('AUTH-006');
        }

        $transaction = $this->db->beginTransaction();
        $authenticatedIdentity = null;
        $denied = false;
        try {
            // The technical user row serializes the failure-count check and
            // audit insert across PHP workers and backend containers.
            $identity = $this->identity(true);
            if ($identity === null) {
                $denied = true;
            } elseif (!$this->configuration->isValid()) {
                $this->recordDenied($identity['id'], $ip, $userAgent, 'configuration');
                $denied = true;
            } elseif (!$identity['isActive'] || !$identity['hasOnlyAdministratorRole']) {
                $reason = !$identity['isActive'] ? 'identity_disabled' : 'administrator_role_invalid';
                Yii::error([
                    'event' => 'authentication.break_glass_configuration_error',
                    'reason' => $reason,
                ], 'security');
                $this->record(
                    'authentication.break_glass_configuration_error',
                    $identity['id'],
                    $ip,
                    $userAgent,
                    ['reason' => $reason],
                );
                $this->recordDenied($identity['id'], $ip, $userAgent, 'configuration');
                $denied = true;
            } elseif ($this->isRateLimited($ip)) {
                $this->recordDenied($identity['id'], $ip, $userAgent, 'rate_limited');
                $denied = true;
            } elseif (!$this->configuration->verify($password)) {
                $this->recordFailure($ip);
                $this->recordDenied($identity['id'], $ip, $userAgent, 'invalid_credentials');
                $denied = true;
            } else {
                $this->record(
                    'authentication.break_glass_succeeded',
                    $identity['id'],
                    $ip,
                    $userAgent,
                );
                $authenticatedIdentity = $identity;
            }

            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        }
        if ($denied) {
            throw new AuthenticationDenied('AUTH-006');
        }

        Yii::warning([
            'event' => 'authentication.break_glass_succeeded',
            'actor_id' => $authenticatedIdentity['id'],
            'ip' => $ip,
            'user_agent' => $this->safeUserAgent($userAgent),
        ], 'security');

        return [
            'id' => $authenticatedIdentity['id'],
            'displayName' => $authenticatedIdentity['displayName'],
        ];
    }

    /** @return array{id: int, displayName: string, isActive: bool, hasOnlyAdministratorRole: bool}|null */
    private function identity(bool $forUpdate = false): ?array
    {
        $sql = 'SELECT u.id, u.display_name AS displayName, u.is_active AS isActive, '
            . "(SELECT COUNT(*) FROM {{%user_roles}} ur WHERE ur.user_id = u.id) AS roleCount, "
            . "EXISTS(SELECT 1 FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.code = 'administrator') AS isAdministrator "
            . 'FROM {{%users}} u WHERE u.ad_login = :login'
            . ($forUpdate ? ' FOR UPDATE' : '');
        $row = $this->db->createCommand($sql, [':login' => self::TECHNICAL_LOGIN])->queryOne();
        if ($row === false) {
            Yii::error(['event' => 'authentication.break_glass_configuration_error', 'reason' => 'identity_missing'], 'security');
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'displayName' => (string) $row['displayName'],
            'isActive' => (bool) $row['isActive'],
            'hasOnlyAdministratorRole' => (int) $row['roleCount'] === 1 && (bool) $row['isAdministrator'],
        ];
    }

    private function isRateLimited(string $ip): bool
    {
        return $this->failureCount('global') >= self::MAX_FAILURES_GLOBAL
            || $this->failureCount($this->ipScope($ip)) >= self::MAX_FAILURES_PER_IP;
    }

    private function failureCount(string $scopeKey): int
    {
        $row = $this->db->createCommand(
            'SELECT failure_count, window_started_at FROM {{%break_glass_rate_limits}} '
            . 'WHERE scope_key = :scope_key FOR UPDATE',
            [':scope_key' => $scopeKey],
        )->queryOne();
        if ($row === false || (string) $row['window_started_at'] < $this->windowCutoff()) {
            return 0;
        }
        return (int) $row['failure_count'];
    }

    private function recordFailure(string $ip): void
    {
        $now = Clock::now();
        foreach (['global', $this->ipScope($ip)] as $scopeKey) {
            $count = $this->failureCount($scopeKey);
            if ($count === 0) {
                $this->db->createCommand()->upsert('{{%break_glass_rate_limits}}', [
                    'scope_key' => $scopeKey,
                    'failure_count' => 1,
                    'window_started_at' => $now,
                    'updated_at' => $now,
                ], [
                    'failure_count' => 1,
                    'window_started_at' => $now,
                    'updated_at' => $now,
                ])->execute();
                continue;
            }
            $this->db->createCommand()->update('{{%break_glass_rate_limits}}', [
                'failure_count' => $count + 1,
                'updated_at' => $now,
            ], ['scope_key' => $scopeKey])->execute();
        }
        $this->db->createCommand()->delete(
            '{{%break_glass_rate_limits}}',
            '[[scope_key]] <> :global AND [[updated_at]] < :cutoff',
            [':global' => 'global', ':cutoff' => $this->windowCutoff()],
        )->execute();
    }

    private function ipScope(string $ip): string
    {
        return 'ip:' . hash('sha256', $ip);
    }

    private function windowCutoff(): string
    {
        return (new \DateTimeImmutable(Clock::now(), new \DateTimeZone('UTC')))
            ->modify('-' . self::FAILURE_WINDOW_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s.u');
    }

    private function recordDenied(int $identityId, string $ip, string $userAgent, string $reason): void
    {
        $this->record(
            'authentication.break_glass_denied',
            $identityId,
            $ip,
            $userAgent,
            ['reason' => $reason],
        );
    }

    /** @param array<string, scalar|null> $details */
    private function record(
        string $eventType,
        int $identityId,
        string $ip,
        string $userAgent,
        array $details = [],
    ): void {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => $eventType,
            'entity_type' => 'user',
            'entity_id' => $identityId,
            'actor_id' => $identityId,
            'rule_id' => 'AUTH-006',
            'payload_json' => [
                'authentication_type' => 'break_glass',
                'ip' => $ip,
                'user_agent' => $this->safeUserAgent($userAgent),
                ...$details,
            ],
            'created_at' => Clock::now(),
        ])->execute();
    }

    private function safeUserAgent(string $userAgent): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $userAgent) ?? '', 0, 255);
    }
}
