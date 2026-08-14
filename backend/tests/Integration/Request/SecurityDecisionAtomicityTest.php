<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\DecideSecurityCommand;
use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Application\Request\UseCase\DecideSecurity;
use App\Infrastructure\Persistence\Request\SecurityDecisionPersistenceAdapter;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class SecurityDecisionAtomicityTest extends TestCase
{
    public function testMutationCheckAndTransitionRollBackWhenMandatoryAuditFails(): void
    {
        $db = $this->connection();
        $outer = $db->beginTransaction();
        $marker = bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');
        try {
            $initiator = $this->createUser($db, "security-atomic-initiator-{$marker}", $now);
            $expert = $this->createUser($db, "security-atomic-expert-{$marker}", $now);
            $officer = $this->createUser($db, "security-atomic-officer-{$marker}", $now);
            $this->grantRole($db, $officer, 'security_officer', $now);
            $input = new CreateRequestInput();
            $input->setAttributes([
                'productName' => "Security atomicity {$marker}", 'manufacturer' => 'Завод',
                'supplier' => 'Поставщик', 'sampleQuantity' => 1, 'testMethod' => 'Методика',
            ]);
            $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($db)))->execute($input->toCommand($initiator))->toArray();
            $requestId = (int) $request['id'];
            $db->createCommand()->update('{{%requests}}', ['status' => 'security_review'], ['id' => $requestId])->execute();
            $db->createCommand()->insert('{{%request_documents}}', [
                'request_id' => $requestId, 'document_type' => 'opinion', 'title' => 'opinion.pdf',
                'created_by' => $expert, 'created_at' => $now,
            ])->execute();
            $documentId = (int) $db->getLastInsertID();
            $db->createCommand()->insert('{{%request_document_versions}}', [
                'document_id' => $documentId, 'version' => 1, 'storage_key' => str_repeat('d', 64),
                'original_name' => 'opinion.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
                'sha256' => str_repeat('d', 64), 'uploaded_by' => $expert, 'created_at' => $now,
            ])->execute();
            $versionId = (int) $db->getLastInsertID();
            $db->createCommand()->insert('{{%expert_opinions}}', [
                'request_id' => $requestId, 'revision' => 1, 'expert_id' => $expert,
                'body' => 'Заключение', 'document_version_id' => $versionId, 'created_at' => $now,
            ])->execute();

            try {
                (new DecideSecurity(new SecurityDecisionPersistenceAdapter($db)))->execute(
                    new DecideSecurityCommand(
                        $requestId,
                        $officer,
                        'approve',
                        null,
                        (int) $request['lock_version'],
                    ),
                );
                self::fail('Expected controlled audit failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('controlled security decision audit failure', $error->getMessage());
            }

            $row = $db->createCommand(
                'SELECT status, lock_version FROM {{%requests}} WHERE id = :id',
                [':id' => $requestId],
            )->queryOne();
            self::assertSame('security_review', (string) $row['status']);
            self::assertSame((int) $request['lock_version'], (int) $row['lock_version']);
            self::assertSame(0, (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id',
                [':id' => $requestId],
            )->queryScalar());
            self::assertSame(0, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%request_transitions}} WHERE request_id = :id AND action = 'security_approve'",
                [':id' => $requestId],
            )->queryScalar());
            self::assertSame(0, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.completed'",
                [':id' => $requestId],
            )->queryScalar());
        } finally {
            if ($outer->isActive) {
                $outer->rollBack();
            }
            $db->close();
        }
    }

    private function createUser(Connection $db, string $login, string $now): int
    {
        $db->createCommand()->insert('{{%users}}', [
            'ad_login' => $login, 'display_name' => $login, 'email' => "{$login}@example.invalid",
            'department' => 'Тестовое подразделение', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ])->execute();
        return (int) $db->getLastInsertID();
    }

    private function grantRole(Connection $db, int $userId, string $role, string $now): void
    {
        $roleId = $db->createCommand('SELECT id FROM {{%roles}} WHERE code = :code', [':code' => $role])->queryScalar();
        $db->createCommand()->insert('{{%user_roles}}', [
            'user_id' => $userId, 'role_id' => $roleId, 'created_at' => $now,
        ])->execute();
    }

    private function connection(): Connection
    {
        $connection = new Connection([
            'dsn' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'ic_test'
            ),
            'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'commandClass' => ControlledSecurityDecisionAuditFailureCommand::class,
        ]);
        $connection->open();
        return $connection;
    }
}
