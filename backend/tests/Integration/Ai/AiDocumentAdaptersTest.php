<?php

declare(strict_types=1);

namespace Tests\Integration\Ai;

use App\Application\Ai\LizaReply;
use App\Infrastructure\Ai\DatabaseAiConversationStore;
use App\Infrastructure\Ai\RequestTechnicalSpecificationDocuments;
use App\Infrastructure\Clock;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class AiDocumentAdaptersTest extends TestCase
{
    private Connection $db;
    private ?int $documentId = null;
    private ?string $conversationId = null;
    private ?string $storageKey = null;
    private ?string $storageRoot = null;
    private ?int $requestId = null;
    private ?int $actorId = null;

    protected function setUp(): void
    {
        $this->db = new Connection([
            'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
            'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4',
        ]);
        $this->db->open();
    }

    protected function tearDown(): void
    {
        if ($this->conversationId !== null) {
            $this->db->createCommand()->delete('{{%ai_conversations}}', ['id' => $this->conversationId])->execute();
        }
        if ($this->documentId !== null) {
            $this->db->createCommand()->delete('{{%request_documents}}', ['id' => $this->documentId])->execute();
        }
        if ($this->requestId !== null) {
            $this->db->createCommand()->delete('{{%requests}}', ['id' => $this->requestId])->execute();
        }
        if ($this->actorId !== null) {
            $this->db->createCommand()->delete('{{%users}}', ['id' => $this->actorId])->execute();
        }
        if ($this->storageKey !== null && $this->storageRoot !== null) {
            (new DocumentStorage($this->storageRoot))->delete($this->storageKey);
            rmdir($this->storageRoot . '/' . substr($this->storageKey, 0, 2) . '/' . substr($this->storageKey, 2, 2));
            rmdir($this->storageRoot . '/' . substr($this->storageKey, 0, 2));
            rmdir($this->storageRoot);
        }
        $this->db->close();
    }

    public function testSelectsReadableDocumentAndPersistsConversationMetadata(): void
    {
        $fixture = $this->fixture();
        $this->storageRoot = sys_get_temp_dir() . '/ic-ai-adapter-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->storageRoot, 0700, true));
        $source = tempnam(sys_get_temp_dir(), 'ic-ai-source-');
        self::assertIsString($source);
        file_put_contents($source, 'synthetic technical specification');
        $storage = new DocumentStorage($this->storageRoot);
        $this->storageKey = $storage->store($source);
        unlink($source);
        $now = Clock::now();
        $this->db->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $fixture['requestId'],
            'title' => 'ТЗ AI adapter ' . bin2hex(random_bytes(4)),
            'created_by' => $fixture['actorId'],
            'created_at' => $now,
        ])->execute();
        $this->documentId = (int) $this->db->getLastInsertID();
        $this->db->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $this->documentId,
            'version' => 1,
            'storage_key' => $this->storageKey,
            'original_name' => 'Техническое задание.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 33,
            'sha256' => hash('sha256', 'synthetic technical specification'),
            'uploaded_by' => $fixture['actorId'],
            'created_at' => $now,
        ])->execute();
        $versionId = (int) $this->db->getLastInsertID();
        $documents = new RequestTechnicalSpecificationDocuments(
            $this->db,
            new DocumentRepository($this->db, $storage),
            $storage,
        );

        $candidates = $documents->candidates($fixture['requestId'], $fixture['actorId']);
        self::assertSame($versionId, $candidates[0]->versionId);
        self::assertSame($storage->path($this->storageKey), $documents->file(
            $fixture['requestId'],
            $versionId,
            $fixture['actorId'],
        )->path);

        $this->conversationId = (new DatabaseAiConversationStore($this->db))->create(
            'analysis',
            $fixture['requestId'],
            $versionId,
            $fixture['actorId'],
            new LizaReply('synthetic-chat', 'synthetic-message', 'result'),
        );
        self::assertSame('synthetic-chat', $this->db->createCommand(
            'SELECT liza_chat_id FROM {{%ai_conversations}} WHERE id = :id',
            [':id' => $this->conversationId],
        )->queryScalar());
    }

    /** @return array{requestId: int, actorId: int} */
    private function fixture(): array
    {
        $now = Clock::now();
        $this->db->createCommand()->insert('{{%users}}', [
            'ad_login' => 'ai.adapter.' . bin2hex(random_bytes(6)),
            'display_name' => 'AI Adapter Test',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $this->actorId = (int) $this->db->getLastInsertID();
        $this->db->createCommand()->insert('{{%requests}}', [
            'number' => random_int(1_000_000, 9_999_999),
            'initiator_id' => $this->actorId,
            'status' => 'draft',
            'product_name' => 'Synthetic product',
            'manufacturer' => 'Synthetic manufacturer',
            'supplier' => 'Synthetic supplier',
            'sample_quantity' => 1,
            'test_method' => 'Synthetic method',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $this->requestId = (int) $this->db->getLastInsertID();
        return ['requestId' => $this->requestId, 'actorId' => $this->actorId];
    }
}
