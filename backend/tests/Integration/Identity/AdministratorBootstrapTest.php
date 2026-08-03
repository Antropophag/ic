<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\AdministratorBootstrap;
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
        $pipes = [];
        try {
            $root = dirname(__DIR__, 3);
            $process = proc_open(
                [PHP_BINARY, $root . '/yii', 'admin/bootstrap'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
            );
            self::assertIsResource($process);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertStringContainsString('Administrator bootstrap complete', $stdout);
            self::assertStringNotContainsString('No bootstrap administrators configured', $stdout);
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
}
