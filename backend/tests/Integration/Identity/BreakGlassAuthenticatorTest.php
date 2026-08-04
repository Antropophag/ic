<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\AuthenticationDenied;
use App\Infrastructure\Identity\BreakGlassAuthenticator;
use App\Infrastructure\Identity\BreakGlassConfiguration;
use App\Infrastructure\Identity\BreakGlassIdentityProvisioner;
use App\Infrastructure\Identity\LdapAuthenticator;
use App\Infrastructure\Identity\LoginAuthenticator;
use App\Infrastructure\Ldap\LdapClient;
use App\Infrastructure\Ldap\LdapConnectionException;
use App\Infrastructure\Ldap\LdapProfile;
use Tests\Integration\IntegrationTestCase;

final class BreakGlassAuthenticatorTest extends IntegrationTestCase
{
    private const LOGIN = 'Emergency.Admin';
    private const PASSWORD = 'correct horse battery staple';
    private const IP = '192.0.2.10';
    private const USER_AGENT = "Integration\nBrowser";

    protected function setUp(): void
    {
        parent::setUp();
        (new BreakGlassIdentityProvisioner($this->db(), $this->validConfiguration()))->provision();
    }

    public function testOrdinaryLoginContinuesToUseLdapWhenBreakGlassIsDisabled(): void
    {
        $ldap = new FakeLdapClient(new LdapProfile('ivanov', 'Иван Иванов', null, null, null));
        $authenticator = $this->loginAuthenticator(new BreakGlassConfiguration(null, null), $ldap);

        $result = $authenticator->authenticate('ivanov', 'ldap-password', self::IP, self::USER_AGENT);

        self::assertSame('Иван Иванов', $result['displayName']);
        self::assertSame([['login' => 'ivanov', 'password' => 'ldap-password']], $ldap->calls);
    }

    public function testSuccessfulBreakGlassAuthenticationDoesNotCallLdapAndHasAdministratorAccess(): void
    {
        $ldap = new FakeLdapClient(null);
        $result = $this->loginAuthenticator($this->validConfiguration(), $ldap)
            ->authenticate(self::LOGIN, self::PASSWORD, self::IP, self::USER_AGENT);

        self::assertSame([], $ldap->calls);
        self::assertSame(BreakGlassAuthenticator::TECHNICAL_LOGIN, $this->scalar(
            'SELECT ad_login FROM {{%users}} WHERE id = :id',
            [':id' => $result['id']],
        ));
        self::assertSame('administrator', $this->scalar(
            'SELECT r.code FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :id',
            [':id' => $result['id']],
        ));

        $event = $this->auditEvent('authentication.break_glass_succeeded');
        self::assertSame('AUTH-006', $event['rule_id']);
        self::assertSame($result['id'], (int) $event['actor_id']);
        self::assertSame([
            'authentication_type' => 'break_glass',
            'ip' => self::IP,
            'user_agent' => 'Integration Browser',
        ], $this->payload($event));
    }

    public function testWrongPasswordIsDeniedAndAuditedWithoutSecrets(): void
    {
        $ldap = new FakeLdapClient(null);
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $authenticator = $this->loginAuthenticator(
            new BreakGlassConfiguration(self::LOGIN, $hash),
            $ldap,
        );

        try {
            $authenticator->authenticate(self::LOGIN, 'do-not-record-me', self::IP, self::USER_AGENT);
            self::fail('Expected invalid break-glass credentials to be denied.');
        } catch (AuthenticationDenied $error) {
            self::assertSame('AUTH-006', $error->ruleId);
        }

        self::assertSame([], $ldap->calls);
        $event = $this->auditEvent('authentication.break_glass_denied');
        $serialized = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('do-not-record-me', $serialized);
        self::assertStringNotContainsString($hash, $serialized);
        self::assertSame('invalid_credentials', $this->payload($event)['reason']);
    }

    public function testDifferentlyCasedLoginIsLdapOnly(): void
    {
        $ldap = new FakeLdapClient(null);
        $authenticator = $this->loginAuthenticator($this->validConfiguration(), $ldap);

        $this->expectException(AuthenticationDenied::class);
        try {
            $authenticator->authenticate('emergency.admin', self::PASSWORD, self::IP, self::USER_AGENT);
        } finally {
            self::assertSame([['login' => 'emergency.admin', 'password' => self::PASSWORD]], $ldap->calls);
            self::assertSame(0, $this->breakGlassEventCount());
        }
    }

    public function testLdapOutageDoesNotTryLocalAuthenticationForAnOrdinaryLogin(): void
    {
        $ldap = new class implements LdapClient {
            public function authenticate(string $login, string $password): ?LdapProfile
            {
                throw new LdapConnectionException('simulated outage');
            }
        };
        $authenticator = $this->loginAuthenticator($this->validConfiguration(), $ldap);

        $this->expectException(LdapConnectionException::class);
        try {
            $authenticator->authenticate('ordinary.user', self::PASSWORD, self::IP, self::USER_AGENT);
        } finally {
            self::assertSame(0, $this->breakGlassEventCount());
        }
    }

    public function testInternalTechnicalLoginCanNeverReachLdap(): void
    {
        $ldap = new FakeLdapClient(new LdapProfile(
            BreakGlassAuthenticator::TECHNICAL_LOGIN,
            'LDAP collision',
            null,
            null,
            null,
        ));
        $authenticator = $this->loginAuthenticator($this->validConfiguration(), $ldap);

        $this->expectException(AuthenticationDenied::class);
        try {
            $authenticator->authenticate(
                BreakGlassAuthenticator::TECHNICAL_LOGIN,
                self::PASSWORD,
                self::IP,
                self::USER_AGENT,
            );
        } finally {
            self::assertSame([], $ldap->calls);
        }
    }

    public function testInvalidConfigurationFailsClosedAndCreatesSafeAuditEvents(): void
    {
        $ldap = new FakeLdapClient(null);
        $authenticator = $this->loginAuthenticator(
            new BreakGlassConfiguration(self::LOGIN, 'invalid-hash'),
            $ldap,
        );

        $this->expectException(AuthenticationDenied::class);
        try {
            $authenticator->authenticate(self::LOGIN, 'secret', self::IP, self::USER_AGENT);
        } finally {
            self::assertSame([], $ldap->calls);
            self::assertSame('invalid_password_hash', $this->payload(
                $this->auditEvent('authentication.break_glass_configuration_error'),
            )['reason']);
            $events = $this->db()->createCommand(
                "SELECT payload_json FROM {{%audit_events}} WHERE event_type LIKE 'authentication.break\\_glass%'",
            )->queryColumn();
            $serialized = json_encode($events, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secret', $serialized);
            self::assertStringNotContainsString('invalid-hash', $serialized);
        }
    }

    public function testDisabledIdentityIsDeniedDespiteCorrectPassword(): void
    {
        $this->db()->createCommand()->update('{{%users}}', ['is_active' => false], [
            'ad_login' => BreakGlassAuthenticator::TECHNICAL_LOGIN,
        ])->execute();

        $this->expectException(AuthenticationDenied::class);
        try {
            $this->loginAuthenticator($this->validConfiguration(), new FakeLdapClient(null))
                ->authenticate(self::LOGIN, self::PASSWORD, self::IP, self::USER_AGENT);
        } finally {
            self::assertSame('identity_disabled', $this->payload(
                $this->auditEvent('authentication.break_glass_configuration_error'),
            )['reason']);
            self::assertSame('configuration', $this->payload(
                $this->auditEvent('authentication.break_glass_denied'),
            )['reason']);
        }
    }

    public function testIdentityWithAnAdditionalRoleIsDenied(): void
    {
        $userId = (int) $this->scalar(
            'SELECT id FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        );
        $this->grantRole($userId, 'employee');

        $this->expectException(AuthenticationDenied::class);
        try {
            $this->loginAuthenticator($this->validConfiguration(), new FakeLdapClient(null))
                ->authenticate(self::LOGIN, self::PASSWORD, self::IP, self::USER_AGENT);
        } finally {
            self::assertSame('administrator_role_invalid', $this->payload(
                $this->auditEvent('authentication.break_glass_configuration_error'),
            )['reason']);
        }
    }

    public function testRotatingHashInvalidatesOldPasswordAndAcceptsNewPassword(): void
    {
        $oldPassword = 'old emergency password';
        $newPassword = 'new emergency password';
        $old = $this->loginAuthenticator(
            new BreakGlassConfiguration(self::LOGIN, password_hash($oldPassword, PASSWORD_DEFAULT)),
            new FakeLdapClient(null),
        );
        self::assertArrayHasKey('id', $old->authenticate(self::LOGIN, $oldPassword, self::IP, self::USER_AGENT));

        $rotated = $this->loginAuthenticator(
            new BreakGlassConfiguration(self::LOGIN, password_hash($newPassword, PASSWORD_DEFAULT)),
            new FakeLdapClient(null),
        );
        try {
            $rotated->authenticate(self::LOGIN, $oldPassword, '192.0.2.11', self::USER_AGENT);
            self::fail('Expected the rotated password hash to reject the old password.');
        } catch (AuthenticationDenied) {
        }
        self::assertArrayHasKey(
            'id',
            $rotated->authenticate(self::LOGIN, $newPassword, '192.0.2.11', self::USER_AGENT),
        );
    }

    public function testRecentFailuresRateLimitFurtherPasswordChecks(): void
    {
        $authenticator = $this->loginAuthenticator($this->validConfiguration(), new FakeLdapClient(null));
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                $authenticator->authenticate(self::LOGIN, 'wrong', self::IP, self::USER_AGENT);
            } catch (AuthenticationDenied) {
            }
        }

        $this->expectException(AuthenticationDenied::class);
        try {
            $authenticator->authenticate(self::LOGIN, self::PASSWORD, self::IP, self::USER_AGENT);
        } finally {
            $events = $this->db()->createCommand(
                "SELECT payload_json FROM {{%audit_events}} WHERE event_type = 'authentication.break_glass_denied' ORDER BY id",
            )->queryColumn();
            self::assertCount(6, $events);
            $last = end($events);
            self::assertIsString($last);
            self::assertSame('rate_limited', json_decode($last, true, 512, JSON_THROW_ON_ERROR)['reason']);
        }
    }

    public function testRateLimitedRequestsDoNotExtendTheRecoveryWindow(): void
    {
        $authenticator = $this->loginAuthenticator($this->validConfiguration(), new FakeLdapClient(null));
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            try {
                $authenticator->authenticate(self::LOGIN, 'wrong', self::IP, self::USER_AGENT);
            } catch (AuthenticationDenied) {
            }
        }
        $this->db()->createCommand()->update(
            '{{%audit_events}}',
            ['created_at' => '2000-01-01 00:00:00.000000'],
            "event_type = 'authentication.break_glass_denied' "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.reason')) = 'invalid_credentials'",
        )->execute();

        self::assertArrayHasKey(
            'id',
            $authenticator->authenticate(self::LOGIN, self::PASSWORD, self::IP, self::USER_AGENT),
        );
    }

    private function validConfiguration(): BreakGlassConfiguration
    {
        return new BreakGlassConfiguration(self::LOGIN, password_hash(self::PASSWORD, PASSWORD_DEFAULT));
    }

    private function loginAuthenticator(BreakGlassConfiguration $configuration, LdapClient $ldap): LoginAuthenticator
    {
        return new LoginAuthenticator(
            new BreakGlassAuthenticator($this->db(), $configuration),
            new LdapAuthenticator($this->db(), $ldap),
        );
    }

    /** @return array<string, mixed> */
    private function auditEvent(string $eventType): array
    {
        $event = $this->db()->createCommand(
            'SELECT * FROM {{%audit_events}} WHERE event_type = :event_type ORDER BY id DESC LIMIT 1',
            [':event_type' => $eventType],
        )->queryOne();
        self::assertIsArray($event, "Missing audit event {$eventType}");
        return $event;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function payload(array $event): array
    {
        $payload = $event['payload_json'];
        return is_array($payload) ? $payload : json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
    }

    private function breakGlassEventCount(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type LIKE 'authentication.break\\_glass%'",
        );
    }
}
