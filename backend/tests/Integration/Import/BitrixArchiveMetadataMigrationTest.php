<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use App\Http\Request\CreateRequest as CreateRequestInput;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;
use yii\db\Migration;

final class BitrixArchiveMetadataMigrationTest extends IntegrationTestCase
{
    public function testRollbackRejectsPopulatedDuplicateTitlesBeforeChangingSchema(): void
    {
        $userId = $this->createUser('dev.it.bitrix.rollback', 'Автор');
        $input = new CreateRequestInput();
        $input->productName = 'Архивное изделие';
        $input->manufacturer = 'Завод';
        $input->supplier = 'Поставщик';
        $input->sampleQuantity = 1;
        $input->testMethod = 'Методика';
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($userId))->toArray();
        foreach (['bitrix24:file:one', 'bitrix24:file:two'] as $legacyId) {
            $this->db()->createCommand()->insert('{{%request_documents}}', [
                'legacy_id' => $legacyId,
                'title_discriminator' => $legacyId,
                'request_id' => $request['id'],
                'document_type' => 'supporting',
                'title' => 'Одинаковое название.pdf',
                'created_by' => $userId,
                'created_at' => '2026-08-12 12:00:00',
            ])->execute();
        }
        $schemaBefore = $this->archiveDocumentSchema();

        $path = dirname(__DIR__, 3) . '/migrations/m260810_000001_add_bitrix_archive_metadata.php';
        require_once $path;
        $class = pathinfo($path, PATHINFO_FILENAME);
        if (!class_exists($class)) {
            self::fail('Archive metadata migration class was not loaded.');
        }
        $loaded = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        if (!$loaded instanceof Migration) {
            self::fail('Archive metadata migration has an unexpected type.');
        }
        $migration = $loaded;
        $migration->db = $this->db();

        try {
            $migration->safeDown();
            self::fail('Populated rollback accepted duplicate document titles.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('duplicate document titles', $error->getMessage());
        }

        self::assertArrayHasKey('title_discriminator', $this->db()->schema->getTableSchema('{{%request_documents}}', true)->columns);
        self::assertSame($schemaBefore, $this->archiveDocumentSchema(), 'Rollback collision must be rejected before any DDL.');
        self::assertSame(2, (int) $this->db()->createCommand(
            'SELECT COUNT(*) FROM {{%request_documents}} WHERE request_id = :id',
            [':id' => $request['id']],
        )->queryScalar());
    }

    /** @return array{columns: list<string>, indexes: list<string>, foreignKeys: list<string>} */
    private function archiveDocumentSchema(): array
    {
        $schema = $this->db()->schema->getTableSchema('{{%request_documents}}', true);
        self::assertNotNull($schema);

        return [
            'columns' => array_keys($schema->columns),
            'indexes' => array_keys($this->db()->schema->findUniqueIndexes($schema)),
            'foreignKeys' => array_keys($schema->foreignKeys),
        ];
    }
}
