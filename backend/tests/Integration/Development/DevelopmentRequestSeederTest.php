<?php

declare(strict_types=1);

namespace Tests\Integration\Development;

use App\Infrastructure\Development\DevelopmentRequestSeeder;
use App\Infrastructure\Document\DocumentStorage;
use Tests\Integration\IntegrationTestCase;
use yii\db\IntegrityException;

final class DevelopmentRequestSeederTest extends IntegrationTestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir() . '/ic-development-seed-test-' . bin2hex(random_bytes(8));
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
        $seeder = new DevelopmentRequestSeeder($this->db(), new DocumentStorage($this->storageRoot));

        $first = $seeder->seed();
        self::assertSame(['requests' => 100, 'comments' => 250, 'documents' => 174], $first);
        self::assertSame(
            ['completed', 'in_progress', 'opinion_preparation', 'registered', 'rejected', 'security_review', 'suspended', 'withdrawn'],
            $this->db()->createCommand('SELECT DISTINCT status FROM {{%requests}} ORDER BY status')->queryColumn(),
        );
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM {{%requests}} WHERE legacy_id IS NOT NULL'));
        self::assertSame(['approve', 'return'], $this->db()->createCommand('SELECT DISTINCT decision FROM {{%security_checks}} ORDER BY decision')->queryColumn());
        self::assertSame(13, (int) $this->scalar("SELECT COUNT(*) FROM {{%request_transitions}} WHERE action = 'security_return'"));
        self::assertSame(
            'Демонстрационная заявка создана. Образцы готовы к передаче в ИЦ.',
            $this->scalar('SELECT body FROM {{%request_comments}} ORDER BY id LIMIT 1'),
        );
        self::assertSame(
            'Требуется уточнить вывод экспертного заключения.',
            $this->scalar("SELECT reason FROM {{%security_checks}} WHERE decision = 'return'"),
        );
        self::assertSame(
            'По результатам демонстрационных испытаний образец соответствует требованиям программы.',
            $this->scalar('SELECT body FROM {{%expert_opinions}} ORDER BY id LIMIT 1'),
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%request_transitions}} transition_event "
                . 'LEFT JOIN {{%request_document_versions}} version ON version.id = transition_event.document_version_id '
                . 'LEFT JOIN {{%request_documents}} document ON document.id = version.document_id '
                . "WHERE transition_event.action = 'upload_report' "
                . "AND (transition_event.document_version_id IS NULL OR document.document_type <> 'report')",
            ),
        );
        self::assertSame(
            'dev.expert2',
            $this->scalar(
                "SELECT u.ad_login FROM {{%request_transitions}} t JOIN {{%requests}} r ON r.id = t.request_id JOIN {{%users}} u ON u.id = t.actor_id WHERE r.status = 'security_review' AND t.action = 'publish_opinion'",
            ),
        );
        self::assertSame(
            ['docx', 'jpeg', 'jpg', 'pdf', 'png', 'xlsx'],
            $this->db()->createCommand(
                "SELECT DISTINCT LOWER(SUBSTRING_INDEX(v.original_name, '.', -1)) "
                . 'FROM {{%request_document_versions}} v '
                . 'JOIN {{%request_documents}} d ON d.id = v.document_id '
                . "WHERE d.document_type = 'attachment' ORDER BY 1",
            )->queryColumn(),
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%request_document_versions}} v "
                . 'JOIN {{%request_documents}} d ON d.id = v.document_id '
                . "WHERE d.document_type IN ('report', 'opinion') "
                . "AND (LOWER(v.original_name) NOT LIKE '%.pdf' OR v.mime_type <> 'application/pdf')",
            ),
        );
        self::assertSame(1, (int) $this->scalar('SELECT MIN(comment_count) FROM (SELECT COUNT(*) comment_count FROM {{%request_comments}} GROUP BY request_id) comments'));
        self::assertSame(4, (int) $this->scalar('SELECT MAX(comment_count) FROM (SELECT COUNT(*) comment_count FROM {{%request_comments}} GROUP BY request_id) comments'));
        self::assertGreaterThan(
            150,
            (int) $this->scalar('SELECT MAX(CHAR_LENGTH(body)) - MIN(CHAR_LENGTH(body)) FROM {{%request_comments}}'),
        );
        self::assertSame($userCount, (int) $this->scalar('SELECT COUNT(*) FROM {{%users}}'));

        $requestIds = $this->db()->createCommand('SELECT id FROM {{%requests}} ORDER BY id')->queryColumn();
        $second = $seeder->seed();
        self::assertSame($first, $second);
        self::assertNotSame($requestIds, $this->db()->createCommand('SELECT id FROM {{%requests}} ORDER BY id')->queryColumn());
        self::assertSame(100, (int) $this->scalar('SELECT COUNT(*) FROM {{%requests}}'));
        self::assertSame(1100, (int) $this->scalar('SELECT value FROM {{%request_number_sequence}} WHERE id = 1'));
    }

    public function testSeedRequiresDevelopmentUsers(): void
    {
        $this->db()->createCommand()->delete('{{%users}}', ['ad_login' => 'dev.expert2'])->execute();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Run dev/seed first");
        (new DevelopmentRequestSeeder($this->db(), new DocumentStorage($this->storageRoot)))->seed();
    }

    public function testAttachmentIsRemovedWhenDatabaseInsertFails(): void
    {
        $seeder = new DevelopmentRequestSeeder($this->db(), new DocumentStorage($this->storageRoot));
        $seeder->seed();
        $requestId = (int) $this->scalar('SELECT id FROM {{%requests}} WHERE number = 1002');
        $userId = (int) $this->scalar("SELECT id FROM {{%users}} WHERE ad_login = 'dev.employee'");
        $filesBefore = glob($this->storageRoot . '/*/*/*') ?: [];
        $method = new \ReflectionMethod($seeder, 'insertAttachment');

        try {
            $method->invoke($seeder, $requestId, 'attachment', 'Сопроводительные материалы 002.jpg', 'image/jpeg', $userId, 1);
            self::fail('The duplicate document title must violate the unique constraint.');
        } catch (IntegrityException) {
            self::assertCount(count($filesBefore), glob($this->storageRoot . '/*/*/*') ?: []);
        }
    }
}
