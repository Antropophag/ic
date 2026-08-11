<?php

declare(strict_types=1);

namespace App\Application\Document;

use App\Domain\Request\ReportPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Document\TestActDocumentGenerator;
use yii\db\Connection;

final class TestActDocumentService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TestActDocumentGenerator $generator,
        private readonly DocumentPersonNameFormatter $nameFormatter = new DocumentPersonNameFormatter(),
    ) {
    }

    /** @return array{documentType: string, actNumber: string, actDate: string, sampleName: string, basis: string, testMethod: string, requestNumber: int, approverName: string, contactEmail: string} */
    public function prepare(int $requestId, int $actorId, ?\DateTimeImmutable $today = null): array
    {
        $request = $this->authorizedRequest($requestId, $actorId);
        $manager = $this->testCenterManager();
        $date = ($today ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('d.m.Y');

        return [
            'documentType' => 'test_act',
            'actNumber' => (string) $request['number'],
            'actDate' => $date,
            'sampleName' => (string) $request['productName'],
            'basis' => 'Заявка № ' . $request['number'],
            'testMethod' => (string) $request['testMethod'],
            'requestNumber' => (int) $request['number'],
            'approverName' => $manager['name'],
            'contactEmail' => $manager['email'],
        ];
    }

    public function generate(int $requestId, int $actorId, TestActInput $input): GeneratedDocument
    {
        $request = $this->authorizedRequest($requestId, $actorId);
        $manager = $this->testCenterManager();
        $data = new TestActDocumentData(
            (int) $request['number'],
            (string) $input->actNumber,
            (string) $input->actDate,
            (string) $request['productName'],
            (string) $input->basis,
            (string) $input->result,
            $manager['name'],
            $manager['email'],
            $this->nameFormatter->abbreviated((string) $request['actorName']),
            (string) ($request['actorPosition'] ?? ''),
        );

        return new GeneratedDocument(
            'Акт_испытаний_заявка_' . $request['number'] . '.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $this->generator->generate($data),
        );
    }

    /** @return array{number: int|string, productName: string, testMethod: string, actorName: string, actorPosition: string|null} */
    private function authorizedRequest(int $requestId, int $actorId): array
    {
        $request = $this->db->createCommand(
            'SELECT r.number, r.product_name AS productName, r.test_method AS testMethod, r.status, '
            . 'r.is_archived AS isArchived, actor.display_name AS actorName, actor.position AS actorPosition, '
            . '(current_executor.user_id = :executor_actor) AS isExecutor, '
            . "EXISTS(SELECT 1 FROM {{%user_roles}} manager_ur JOIN {{%roles}} manager_role ON manager_role.id = manager_ur.role_id WHERE manager_ur.user_id = :manager_actor AND manager_role.code IN ('ic_manager', 'laboratory_manager')) AS isManager, "
            . "EXISTS(SELECT 1 FROM {{%request_documents}} report WHERE report.request_id = r.id AND report.document_type = 'report' AND report.deleted_at IS NULL) AS hasActiveReport "
            . 'FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id AND actor.is_active = 1 '
            . 'LEFT JOIN {{%request_assignments}} current_executor ON current_executor.request_id = r.id '
            . "AND current_executor.assignment_type = 'executor' AND current_executor.valid_to IS NULL "
            . 'WHERE r.id = :request_id',
            [
                ':request_id' => $requestId,
                ':actor_id' => $actorId,
                ':executor_actor' => $actorId,
                ':manager_actor' => $actorId,
            ],
        )->queryOne();
        if ($request === false) {
            throw new RequestNotFound('Request not found');
        }
        if ((int) $request['isArchived'] === 1) {
            (new ReportPolicy())->assertCanUpload(RequestStatus::from((string) $request['status']), false, false, true);
        }
        (new ReportPolicy())->assertCanUpload(
            RequestStatus::from((string) $request['status']),
            (bool) $request['isExecutor'],
            (bool) $request['isManager'],
            (bool) $request['hasActiveReport'],
        );

        return $request;
    }

    /** @return array{name: string, email: string} */
    private function testCenterManager(): array
    {
        $managers = $this->db->createCommand(
            'SELECT TRIM(u.display_name) AS name, TRIM(u.email) AS email FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'JOIN {{%roles}} role ON role.id = ur.role_id '
            . "WHERE u.is_active = 1 AND role.code = 'ic_manager' ORDER BY u.id LIMIT 2",
        )->queryAll();
        if (count($managers) !== 1) {
            throw new TestActConfigurationError(
                'Для формирования акта должен быть назначен ровно один активный руководитель ИЦ.',
            );
        }
        $manager = $managers[0];
        if ($manager['name'] === '' || $manager['email'] === null || $manager['email'] === '') {
            throw new TestActConfigurationError(
                'У руководителя ИЦ должны быть заполнены ФИО и email.',
            );
        }

        return [
            'name' => $this->nameFormatter->abbreviated((string) $manager['name']),
            'email' => (string) $manager['email'],
        ];
    }
}
