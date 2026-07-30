<?php

declare(strict_types=1);

namespace Tests\Integration\Development;

use App\Infrastructure\Development\DemoRequestSeeder;
use App\Infrastructure\Document\DocumentStorage;
use Tests\Integration\IntegrationTestCase;
use yii\db\IntegrityException;

final class DemoRequestSeederTest extends IntegrationTestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir() . '/ic-demo-seed-test-' . bin2hex(random_bytes(8));
        mkdir($this->storageRoot, 0700, true);
        foreach (['dev.user', 'dev.executor', 'dev.executor.naumov', 'dev.employee', 'dev.expert', 'dev.expert2', 'dev.security'] as $login) {
            if ($this->scalar('SELECT id FROM {{%users}} WHERE ad_login = :login', [':login' => $login]) === false) {
                $this->createUser($login, $login);
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            $files = glob($this->storageRoot . '/*/*/*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $directories = glob($this->storageRoot . '/*/*', GLOB_ONLYDIR) ?: [];
            foreach ($directories as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
            $directories = glob($this->storageRoot . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($directories as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
            if (is_dir($this->storageRoot)) {
                rmdir($this->storageRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testSeedCreatesFullSyntheticRegistryAndCanResetIt(): void
    {
        $userCount = (int) $this->scalar('SELECT COUNT(*) FROM {{%users}}');
        $seeder = new DemoRequestSeeder($this->db(), new DocumentStorage($this->storageRoot));

        $first = $seeder->seed();
        self::assertSame(['requests' => 7, 'comments' => 13, 'documents' => 9], $first);
        self::assertSame(
            ['completed', 'in_progress', 'opinion_preparation', 'registered', 'rejected', 'security_review', 'suspended'],
            $this->db()->createCommand('SELECT status FROM {{%requests}} ORDER BY status')->queryColumn(),
        );
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM {{%requests}} WHERE legacy_id IS NOT NULL'));
        self::assertSame(['approve', 'return'], $this->db()->createCommand('SELECT decision FROM {{%security_checks}} ORDER BY decision')->queryColumn());
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM {{%request_transitions}} WHERE action = 'security_return'"));
        self::assertSame(
            'dev.expert2',
            $this->scalar(
                "SELECT u.ad_login FROM {{%request_transitions}} t JOIN {{%requests}} r ON r.id = t.request_id JOIN {{%users}} u ON u.id = t.actor_id WHERE r.status = 'security_review' AND t.action = 'publish_opinion'",
            ),
        );
        self::assertSame($userCount, (int) $this->scalar('SELECT COUNT(*) FROM {{%users}}'));

        $requestIds = $this->db()->createCommand('SELECT id FROM {{%requests}} ORDER BY id')->queryColumn();
        $second = $seeder->seed();
        self::assertSame($first, $second);
        self::assertNotSame($requestIds, $this->db()->createCommand('SELECT id FROM {{%requests}} ORDER BY id')->queryColumn());
        self::assertSame(7, (int) $this->scalar('SELECT COUNT(*) FROM {{%requests}}'));
        self::assertSame(1007, (int) $this->scalar('SELECT value FROM {{%request_number_sequence}} WHERE id = 1'));
    }

    public function testSeedRequiresDevelopmentUsers(): void
    {
        $this->db()->createCommand()->delete('{{%users}}', ['ad_login' => 'dev.expert2'])->execute();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Run dev/seed first");
        (new DemoRequestSeeder($this->db(), new DocumentStorage($this->storageRoot)))->seed();
    }

    public function testAttachmentIsRemovedWhenDatabaseInsertFails(): void
    {
        $seeder = new DemoRequestSeeder($this->db(), new DocumentStorage($this->storageRoot));
        $seeder->seed();
        $requestId = (int) $this->scalar('SELECT id FROM {{%requests}} WHERE number = 1002');
        $userId = (int) $this->scalar("SELECT id FROM {{%users}} WHERE ad_login = 'dev.employee'");
        $filesBefore = glob($this->storageRoot . '/*/*/*') ?: [];
        $method = new \ReflectionMethod($seeder, 'insertAttachment');

        try {
            $method->invoke($seeder, $requestId, 'attachment', 'Программа испытаний.txt', $userId, 1);
            self::fail('The duplicate document title must violate the unique constraint.');
        } catch (IntegrityException) {
            self::assertCount(count($filesBefore), glob($this->storageRoot . '/*/*/*') ?: []);
        }
    }
}
