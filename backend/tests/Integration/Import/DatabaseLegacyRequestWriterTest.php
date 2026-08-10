<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use App\Application\Import\LegacyImportOutcome;
use App\Application\Import\LegacyRequestData;
use App\Application\Import\LegacyUserData;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Import\DatabaseLegacyRequestWriter;
use App\Infrastructure\Import\LegacyProductNameSchemaGuard;
use App\Infrastructure\Request\RequestQuery;
use DateTimeImmutable;
use Tests\Integration\IntegrationTestCase;

final class DatabaseLegacyRequestWriterTest extends IntegrationTestCase
{
    private function request(
        string $legacyId,
        string $creatorLegacyId = '1595',
        string $department = 'Лаборатория',
        ?string $departmentExternalId = null,
        bool $creatorActive = true,
        ?int $sampleQuantity = 2,
        ?string $legacySampleQuantityRaw = '2',
        string $productName = 'Тестовое изделие',
    ): LegacyRequestData {
        return new LegacyRequestData(
            $legacyId,
            (int) substr($legacyId, (int) strrpos($legacyId, ':') + 1),
            $productName,
            'Тестовый завод',
            'Тестовый поставщик',
            $sampleQuantity,
            $legacySampleQuantityRaw,
            'Метод испытаний',
            RequestStatus::Completed,
            new DateTimeImmutable('2024-01-01T12:00:00+00:00'),
            new LegacyUserData(
                $creatorLegacyId,
                "user{$creatorLegacyId}",
                'Иванов Иван',
                "user{$creatorLegacyId}@example.test",
                'Инженер',
                $creatorActive,
            ),
            $department,
            1,
            0,
            $departmentExternalId,
        );
    }

    public function testFirstWriteCreatesRequestWithAuditAndTransition(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $outcome = $writer->write($this->request('bitrix24:114:501'));

        self::assertSame(LegacyImportOutcome::Created, $outcome);

        $requestId = $this->scalar(
            'SELECT id FROM {{%requests}} WHERE legacy_id = :legacy_id',
            [':legacy_id' => 'bitrix24:114:501'],
        );
        self::assertNotFalse($requestId);

        $stored = $this->db()->createCommand(
            'SELECT number, status FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
        self::assertNotFalse($stored);
        self::assertSame(501, (int) $stored['number']);
        $status = $stored['status'];
        self::assertSame('completed', $status);
        $snapshot = $this->db()->createCommand(
            'SELECT department_name, department_source FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
        self::assertSame(['department_name' => 'Лаборатория', 'department_source' => 'bitrix24'], $snapshot);

        $transitionCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%request_transitions}} WHERE request_id = :id "
            . "AND action = 'import' AND rule_id = 'IMP-002' AND from_status IS NULL AND reason IS NULL",
            [':id' => $requestId],
        );
        self::assertSame(1, (int) $transitionCount);

        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id "
            . "AND event_type = 'request.imported' AND rule_id = 'IMP-001'",
            [':id' => $requestId],
        );
        self::assertSame(1, (int) $auditCount);
        self::assertSame(
            501,
            (int) $this->scalar('SELECT value FROM {{%request_number_sequence}} WHERE id = 1'),
        );
    }

    public function testRepeatedWriteWithSameLegacyIdIsSkipped(): void
    {
        // IMP-001: повторный импорт одной записи не создаёт дубликат заявки.
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $legacyId = 'bitrix24:114:502';

        self::assertSame(LegacyImportOutcome::Created, $writer->write($this->request($legacyId)));
        self::assertSame(LegacyImportOutcome::Skipped, $writer->write($this->request($legacyId)));

        $count = $this->scalar(
            'SELECT COUNT(*) FROM {{%requests}} WHERE legacy_id = :legacy_id',
            [':legacy_id' => $legacyId],
        );
        self::assertSame(1, (int) $count);
    }

    public function testImportedRequestStoresUnknownQuantityAndOriginalValue(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request(
            'bitrix24:114:512',
            sampleQuantity: null,
            legacySampleQuantityRaw: 'По 1 шт. каждого вида',
        ));

        $stored = $this->db()->createCommand(
            'SELECT sample_quantity, legacy_sample_quantity_raw FROM {{%requests}} WHERE legacy_id = :id',
            [':id' => 'bitrix24:114:512'],
        )->queryOne();

        self::assertNotFalse($stored);
        self::assertNull($stored['sample_quantity']);
        self::assertSame('По 1 шт. каждого вида', $stored['legacy_sample_quantity_raw']);

        $requestId = (int) $this->scalar(
            'SELECT id FROM {{%requests}} WHERE legacy_id = :id',
            [':id' => 'bitrix24:114:512'],
        );
        $actorId = (int) $this->scalar("SELECT id FROM {{%users}} WHERE ad_login = 'user1595'");
        $apiItem = (new RequestQuery($this->db()))->findDetails($requestId, $actorId)['item'];
        self::assertNull($apiItem['sample_quantity']);
        self::assertSame('По 1 шт. каждого вида', $apiItem['legacy_sample_quantity_raw']);
    }

    public function testImportedLongProductNameIsStoredAndReturnedInFull(): void
    {
        $name = str_repeat('Длинное наименование ', 86) . 'конец';
        self::assertGreaterThan(1728, mb_strlen($name));
        self::assertLessThanOrEqual(2000, mb_strlen($name));

        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:513', productName: $name));

        $requestId = (int) $this->scalar(
            'SELECT id FROM {{%requests}} WHERE legacy_id = :id',
            [':id' => 'bitrix24:114:513'],
        );
        $actorId = (int) $this->scalar("SELECT id FROM {{%users}} WHERE ad_login = 'user1595'");
        $apiItem = (new RequestQuery($this->db()))->findDetails($requestId, $actorId)['item'];

        self::assertSame($name, $apiItem['product_name']);
    }

    public function testProductNameMigrationRefusesLossyRollback(): void
    {
        $name = str_repeat('Я', 501);
        (new DatabaseLegacyRequestWriter($this->db()))->write(
            $this->request('bitrix24:114:514', productName: $name),
        );
        try {
            LegacyProductNameSchemaGuard::assertCanRestoreLimit($this->db(), 500);
            self::fail('Lossy rollback was not rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'Cannot restore VARCHAR(500) product_name while imported requests contain longer values.',
                $error->getMessage(),
            );
        }

        self::assertSame($name, $this->scalar(
            'SELECT product_name FROM {{%requests}} WHERE legacy_id = :id',
            [':id' => 'bitrix24:114:514'],
        ));
    }

    public function testImportDoesNotMoveNumberSequenceBackwards(): void
    {
        $this->db()->createCommand()->update('{{%request_number_sequence}}', ['value' => 900], ['id' => 1])->execute();

        $writer = new DatabaseLegacyRequestWriter($this->db());
        self::assertSame(
            LegacyImportOutcome::Created,
            $writer->write($this->request('bitrix24:114:508')),
        );

        self::assertSame(
            900,
            (int) $this->scalar('SELECT value FROM {{%request_number_sequence}} WHERE id = 1'),
        );
    }

    public function testImportedActiveInitiatorIsPreProvisionedWithEmployeeRole(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:503', '77001'));

        $user = $this->db()->createCommand(
            "SELECT id, is_active, display_name, email, position, department FROM {{%users}} "
            . "WHERE ad_login = 'user77001'",
        )->queryOne();

        self::assertNotFalse($user);
        self::assertSame(1, (int) $user['is_active']);
        self::assertSame('Иванов Иван', $user['display_name']);
        self::assertSame('user77001@example.test', $user['email']);
        self::assertSame('Инженер', $user['position']);
        self::assertSame('Лаборатория', $user['department']);
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id "
            . "WHERE ur.user_id = :id AND r.code = 'employee'",
            [':id' => $user['id']],
        ));
    }

    public function testImportedInactiveInitiatorRemainsBlocked(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:509', '77005', 'Лаборатория', null, false));

        $user = $this->db()->createCommand(
            "SELECT id, is_active FROM {{%users}} WHERE ad_login = 'user77005'",
        )->queryOne();
        self::assertNotFalse($user);
        self::assertSame(0, (int) $user['is_active']);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :id',
            [':id' => $user['id']],
        ));
    }

    public function testImportDoesNotOverwriteExistingLocalIdentity(): void
    {
        $this->db()->createCommand()->insert('{{%users}}', [
            'ad_login' => 'user77006',
            'display_name' => 'Актуальное имя AD',
            'email' => 'actual@example.test',
            'is_active' => false,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ])->execute();
        $existingId = (int) $this->db()->getLastInsertID();

        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:510', '77006'));

        $user = $this->db()->createCommand(
            'SELECT id, display_name, email, is_active FROM {{%users}} WHERE ad_login = :login',
            [':login' => 'user77006'],
        )->queryOne();
        self::assertSame($existingId, (int) $user['id']);
        self::assertSame('Актуальное имя AD', $user['display_name']);
        self::assertSame('actual@example.test', $user['email']);
        self::assertSame(0, (int) $user['is_active']);
    }

    public function testSameLegacyCreatorIsReusedAcrossRequests(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:504', '77002'));
        $writer->write($this->request('bitrix24:114:505', '77002'));

        $userCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'user77002'",
        );
        self::assertSame(1, (int) $userCount);

        $requestCount = $this->scalar(
            'SELECT COUNT(*) FROM {{%requests}} r JOIN {{%users}} u ON u.id = r.initiator_id '
            . "WHERE u.ad_login = 'user77002'",
        );
        self::assertSame(2, (int) $requestCount);
    }

    public function testMissingDepartmentIsStoredAsNull(): void
    {
        // IMP-004: избыточные/отсутствующие персональные данные не переносятся как есть.
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:506', '77003', ''));

        $department = $this->db()->createCommand(
            "SELECT department FROM {{%users}} WHERE ad_login = 'user77003'",
        )->queryScalar();
        self::assertNull($department);
        $snapshot = $this->db()->createCommand(
            "SELECT department_name, department_source FROM {{%requests}} WHERE legacy_id = 'bitrix24:114:506'",
        )->queryOne();
        self::assertSame(['department_name' => null, 'department_source' => 'unknown'], $snapshot);
    }

    public function testExternalDepartmentIdKeepsBitrixSourceWithoutName(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:507', '77004', '', 'BX-42'));

        $snapshot = $this->db()->createCommand(
            "SELECT department_name, department_external_id, department_source FROM {{%requests}} "
            . "WHERE legacy_id = 'bitrix24:114:507'",
        )->queryOne();
        self::assertSame([
            'department_name' => null,
            'department_external_id' => 'BX-42',
            'department_source' => 'bitrix24',
        ], $snapshot);
    }

    public function testMissingEmployeeRoleRollsBackImportedIdentityAndRequest(): void
    {
        $this->db()->createCommand()->update('{{%roles}}', ['code' => 'employee_unavailable'], ['code' => 'employee'])->execute();
        try {
            (new DatabaseLegacyRequestWriter($this->db()))->write(
                $this->request('bitrix24:114:511', '77007'),
            );
            self::fail('Import must fail when the required role is unavailable.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('employee role', $exception->getMessage());
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'user77007'",
            ));
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%requests}} WHERE legacy_id = 'bitrix24:114:511'",
            ));
        } finally {
            $this->db()->createCommand()->update('{{%roles}}', ['code' => 'employee'], ['code' => 'employee_unavailable'])->execute();
        }
    }
}
