<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\AdministratorBootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

final class AdministratorBootstrapTest extends IntegrationTestCase
{
    public function testConsoleBootstrapDoesNotTreatZeroAsEmptyConfiguration(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the console contract test.');
        }

        $previous = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        putenv('BOOTSTRAP_ADMIN_AD_LOGINS=0');
        try {
            ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr] =
                $this->runBootstrapCommand();
            self::assertSame(0, $exitCode);
            self::assertStringContainsString('Administrator bootstrap complete', $stdout);
            self::assertStringNotContainsString('Administrator bootstrap failed', $stderr);
        } finally {
            $this->db()->createCommand()->delete('{{%user_roles}}', [
                'user_id' => (new \yii\db\Query())
                    ->select('id')
                    ->from('{{%users}}')
                    ->where(['ad_login' => '0']),
            ])->execute();
            $this->db()->createCommand()->delete('{{%users}}', ['ad_login' => '0'])->execute();
            $previous === false ? putenv('BOOTSTRAP_ADMIN_AD_LOGINS') : putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $previous);
        }
    }

    public function testConsoleRejectsNonCanonicalSeparatorWithoutLeakingConfiguredValue(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the console contract test.');
        }

        $configured = 'sensitive.login another.login';
        $previous = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $configured);
        try {
            ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr] =
                $this->runBootstrapCommand();
            self::assertSame(65, $exitCode);
            self::assertStringContainsString('Administrator bootstrap failed', $stderr);
            self::assertStringNotContainsString($configured, $stdout . $stderr);
            self::assertStringNotContainsString('Stack trace:', $stderr);
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login IN ('sensitive.login', 'another.login')",
            ));
        } finally {
            $previous === false
                ? putenv('BOOTSTRAP_ADMIN_AD_LOGINS')
                : putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $previous);
        }
    }

    public function testConsoleSuccessDoesNotLeakConfiguredLogin(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the console contract test.');
        }

        $login = 'sensitive.bootstrap.login';
        $previous = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $login);
        try {
            ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr] =
                $this->runBootstrapCommand();
            self::assertSame(0, $exitCode);
            self::assertStringContainsString('Administrator bootstrap complete', $stdout);
            self::assertStringNotContainsString($login, $stdout . $stderr);
        } finally {
            $userId = $this->scalar(
                'SELECT id FROM {{%users}} WHERE ad_login = :ad_login',
                [':ad_login' => $login],
            );
            if ($userId !== false) {
                $this->db()->createCommand()->delete('{{%user_roles}}', ['user_id' => $userId])->execute();
                $this->db()->createCommand()->delete('{{%users}}', ['id' => $userId])->execute();
            }
            $previous === false
                ? putenv('BOOTSTRAP_ADMIN_AD_LOGINS')
                : putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $previous);
        }
    }

    public function testConsoleTreatsMissingConfigurationAsSuccessfulSkip(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the console contract test.');
        }

        $previous = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        putenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        try {
            ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr] =
                $this->runBootstrapCommand();
            self::assertSame(0, $exitCode);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Administrator bootstrap skipped', $stderr);
        } finally {
            $previous === false
                ? putenv('BOOTSTRAP_ADMIN_AD_LOGINS')
                : putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $previous);
        }
    }

    public function testConsoleTreatsWhitespaceOnlyConfigurationAsSuccessfulSkip(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the console contract test.');
        }

        $previous = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        putenv('BOOTSTRAP_ADMIN_AD_LOGINS=   ');
        try {
            ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr] =
                $this->runBootstrapCommand();
            self::assertSame(0, $exitCode);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Administrator bootstrap skipped', $stderr);
        } finally {
            $previous === false
                ? putenv('BOOTSTRAP_ADMIN_AD_LOGINS')
                : putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $previous);
        }
    }

    public function testConsoleTreatsListOfEmptyElementsAsSuccessfulSkip(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the console contract test.');
        }

        $previous = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        putenv('BOOTSTRAP_ADMIN_AD_LOGINS= , , ');
        try {
            ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr] =
                $this->runBootstrapCommand();
            self::assertSame(0, $exitCode);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Administrator bootstrap skipped', $stderr);
        } finally {
            $previous === false
                ? putenv('BOOTSTRAP_ADMIN_AD_LOGINS')
                : putenv('BOOTSTRAP_ADMIN_AD_LOGINS=' . $previous);
        }
    }

    public function testCreatesAdministratorsAndIsIdempotent(): void
    {
        $bootstrap = new AdministratorBootstrap($this->db());

        $first = $bootstrap->bootstrap([' Bootstrap.Admin.One ', 'bootstrap.admin.two', 'bootstrap.admin.one']);
        $second = $bootstrap->bootstrap(['bootstrap.admin.one', 'bootstrap.admin.two']);

        self::assertSame(['usersCreated' => 2, 'rolesAssigned' => 4], $first);
        self::assertSame(['usersCreated' => 0, 'rolesAssigned' => 0], $second);
        self::assertSame(2, (int) $this->scalar(
            "SELECT COUNT(DISTINCT u.id) FROM {{%users}} u "
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.ad_login IN ('bootstrap.admin.one', 'bootstrap.admin.two') AND r.code = 'administrator'",
        ));
        self::assertSame(4, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%user_roles}} ur JOIN {{%users}} u ON u.id = ur.user_id "
            . "WHERE u.ad_login IN ('bootstrap.admin.one', 'bootstrap.admin.two')",
        ));
        self::assertSame(4, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%user_roles}} ur JOIN {{%users}} u ON u.id = ur.user_id "
            . "WHERE u.ad_login IN ('bootstrap.admin.one', 'bootstrap.admin.two') AND ur.assigned_by IS NULL",
        ));
    }

    public function testPreservesExistingProfileDataAndRoles(): void
    {
        $userId = $this->createUser('bootstrap.existing', 'Existing AD profile', 'existing@example.invalid');
        $this->grantRole($userId, 'expert');

        $result = (new AdministratorBootstrap($this->db()))->bootstrap(['bootstrap.existing']);

        self::assertSame(['usersCreated' => 0, 'rolesAssigned' => 2], $result);
        self::assertSame('Existing AD profile', $this->scalar(
            'SELECT display_name FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        ));
        self::assertSame(3, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :id',
            [':id' => $userId],
        ));
    }

    public function testDisabledAdministratorRollsBackWholeBootstrap(): void
    {
        $this->createUser('disabled.admin', 'Disabled administrator', isActive: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('locally disabled');

        try {
            (new AdministratorBootstrap($this->db()))->bootstrap([
                'new.admin',
                'disabled.admin',
            ]);
        } finally {
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'new.admin'",
            ));
        }
    }

    public function testRejectsInvalidLoginBeforeChangingDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            (new AdministratorBootstrap($this->db()))->bootstrap([
                'valid.login',
                'invalid@example.com',
            ]);
        } finally {
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'valid.login'",
            ));
        }
    }

    #[DataProvider('invalidLoginProvider')]
    public function testRejectsUnsupportedLoginFormats(string $login): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AdministratorBootstrap($this->db()))->bootstrap([$login]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLoginProvider(): iterable
    {
        yield 'UPN' => ['user@example.invalid'];
        yield 'domain-qualified' => ['DOMAIN\\user'];
        yield 'Cyrillic' => ['пользователь'];
        yield 'control character' => ["user\nname"];
        yield 'internal space' => ['user name'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    public function testRejectsEmptyElementInTheMiddleBeforeChangingDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            (new AdministratorBootstrap($this->db()))->bootstrap([
                'first.admin',
                ' ',
                'third.admin',
            ]);
        } finally {
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login IN ('first.admin', 'third.admin')",
            ));
        }
    }

    public function testRemovingLoginFromConfigurationDoesNotRevokeExistingRoles(): void
    {
        $keptUser = $this->createUser('kept.admin', 'Kept administrator');
        $this->grantRole($keptUser, 'employee');
        $this->grantRole($keptUser, 'administrator');
        $otherUser = $this->createUser('other.admin', 'Other administrator');
        $this->grantRole($otherUser, 'employee');
        $this->grantRole($otherUser, 'administrator');

        (new AdministratorBootstrap($this->db()))->bootstrap(['kept.admin']);

        self::assertSame(2, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%user_roles}} ur '
            . 'JOIN {{%users}} u ON u.id = ur.user_id '
            . "WHERE u.ad_login = 'other.admin'",
        ));
    }

    public function testExistingProfileAndRoleAssignmentMetadataArePreserved(): void
    {
        $actorId = $this->createUser('assignment.actor', 'Assignment actor');
        $userId = $this->createUser('bootstrap.preserved', 'Original name', 'original@example.invalid');
        $this->db()->createCommand()->update('{{%users}}', [
            'department' => 'Original department',
            'position' => 'Original position',
        ], ['id' => $userId])->execute();
        $this->grantRole($userId, 'expert');
        $administratorRoleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'administrator'");
        $this->db()->createCommand()->insert('{{%user_roles}}', [
            'user_id' => $userId,
            'role_id' => $administratorRoleId,
            'assigned_by' => $actorId,
            'created_at' => \App\Infrastructure\Clock::now(),
        ])->execute();

        $result = (new AdministratorBootstrap($this->db()))->bootstrap(['bootstrap.preserved']);

        self::assertSame(['usersCreated' => 0, 'rolesAssigned' => 1], $result);
        $row = $this->db()->createCommand(
            'SELECT ad_login, display_name, email, department, position, is_active FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        )->queryOne();
        self::assertSame('bootstrap.preserved', $row['ad_login']);
        self::assertSame('Original name', $row['display_name']);
        self::assertSame('original@example.invalid', $row['email']);
        self::assertSame('Original department', $row['department']);
        self::assertSame('Original position', $row['position']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame($actorId, (int) $this->scalar(
            'SELECT assigned_by FROM {{%user_roles}} WHERE user_id = :user_id AND role_id = :role_id',
            [':user_id' => $userId, ':role_id' => $administratorRoleId],
        ));
        self::assertSame(3, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :user_id',
            [':user_id' => $userId],
        ));
    }

    public function testMissingRequiredRoleRollsBackWithoutCreatingUsers(): void
    {
        $roleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'administrator'");
        $this->db()->createCommand()->update(
            '{{%roles}}',
            ['code' => 'administrator.missing'],
            ['id' => $roleId],
        )->execute();

        $this->expectException(\RuntimeException::class);
        try {
            (new AdministratorBootstrap($this->db()))->bootstrap(['missing.role.admin']);
        } finally {
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'missing.role.admin'",
            ));
        }
    }

    /** @return array{exitCode: int, stdout: string, stderr: string} */
    private function runBootstrapCommand(): array
    {
        $root = dirname(__DIR__, 3);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $root . '/yii', 'admin/bootstrap'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
