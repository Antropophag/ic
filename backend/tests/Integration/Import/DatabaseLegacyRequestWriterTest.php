<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use App\Application\Import\LegacyImportOutcome;
use App\Application\Import\LegacyRequestData;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Import\DatabaseLegacyRequestWriter;
use DateTimeImmutable;
use Tests\Integration\IntegrationTestCase;

final class DatabaseLegacyRequestWriterTest extends IntegrationTestCase
{
    private function request(
        string $legacyId,
        string $creatorLegacyId = '1595',
        string $department = 'Лаборатория',
    ): LegacyRequestData {
        return new LegacyRequestData(
            $legacyId,
            'Тестовое изделие',
            'Тестовый завод',
            'Тестовый поставщик',
            2,
            'Метод испытаний',
            RequestStatus::Completed,
            new DateTimeImmutable('2024-01-01T12:00:00+00:00'),
            $creatorLegacyId,
            'Иванов Иван',
            $department,
            1,
            0,
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

        $status = $this->scalar('SELECT status FROM {{%requests}} WHERE id = :id', [':id' => $requestId]);
        self::assertSame('completed', $status);
        $snapshot = $this->db()->createCommand(
            'SELECT department_name, department_source FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
        self::assertSame(['department_name' => 'Лаборатория', 'department_source' => 'bitrix24'], $snapshot);

        $transitionCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%request_transitions}} WHERE request_id = :id "
            . "AND action = 'import' AND rule_id = 'IMP-002' AND from_status IS NULL",
            [':id' => $requestId],
        );
        self::assertSame(1, (int) $transitionCount);

        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id "
            . "AND event_type = 'request.imported' AND rule_id = 'IMP-001'",
            [':id' => $requestId],
        );
        self::assertSame(1, (int) $auditCount);
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

    public function testImportedInitiatorIsCreatedAsInactivePlaceholder(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:503', '77001'));

        $user = $this->db()->createCommand(
            "SELECT is_active, display_name, department FROM {{%users}} WHERE ad_login = 'legacy.bitrix24.77001'",
        )->queryOne();

        self::assertNotFalse($user);
        self::assertSame(0, (int) $user['is_active']);
        self::assertSame('Иванов Иван', $user['display_name']);
        self::assertSame('Лаборатория', $user['department']);
    }

    public function testSameLegacyCreatorIsReusedAcrossRequests(): void
    {
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:504', '77002'));
        $writer->write($this->request('bitrix24:114:505', '77002'));

        $userCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'legacy.bitrix24.77002'",
        );
        self::assertSame(1, (int) $userCount);

        $requestCount = $this->scalar(
            'SELECT COUNT(*) FROM {{%requests}} r JOIN {{%users}} u ON u.id = r.initiator_id '
            . "WHERE u.ad_login = 'legacy.bitrix24.77002'",
        );
        self::assertSame(2, (int) $requestCount);
    }

    public function testMissingDepartmentIsStoredAsNull(): void
    {
        // IMP-004: избыточные/отсутствующие персональные данные не переносятся как есть.
        $writer = new DatabaseLegacyRequestWriter($this->db());
        $writer->write($this->request('bitrix24:114:506', '77003', ''));

        $department = $this->db()->createCommand(
            "SELECT department FROM {{%users}} WHERE ad_login = 'legacy.bitrix24.77003'",
        )->queryScalar();
        self::assertNull($department);
        $snapshot = $this->db()->createCommand(
            "SELECT department_name, department_source FROM {{%requests}} WHERE legacy_id = 'bitrix24:114:506'",
        )->queryOne();
        self::assertSame(['department_name' => null, 'department_source' => 'unknown'], $snapshot);
    }
}
