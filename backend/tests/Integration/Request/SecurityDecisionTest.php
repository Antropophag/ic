<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\DecideSecurityCommand;
use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Application\Request\UseCase\DecideSecurity;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\SecurityDecisionDenied;
use App\Infrastructure\Clock;
use App\Infrastructure\Persistence\Request\SecurityDecisionPersistenceAdapter;
use Tests\Integration\IntegrationTestCase;

final class SecurityDecisionTest extends IntegrationTestCase
{
    public function testApproveCompletesRequestAndRecordsAllEffectsAndDocumentLinks(): void
    {
        $fixture = $this->fixture('approve');
        $result = $this->decide($fixture, 'approve');

        self::assertSame($fixture['requestId'], $result['requestId']);
        self::assertSame('approve', $result['decision']);
        self::assertSame('completed', $result['status']);
        self::assertSame((int) $fixture['lockVersion'] + 1, $result['lockVersion']);
        self::assertSame('security_approve', $this->scalar(
            'SELECT action FROM {{%request_transitions}} WHERE request_id = :id ORDER BY id DESC LIMIT 1',
            [':id' => $fixture['requestId']],
        ));
        self::assertSame('SEC-002', $this->scalar(
            "SELECT rule_id FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.security_decided'",
            [':id' => $fixture['requestId']],
        ));
        $notification = $this->db()->createCommand(
            "SELECT event_type, recipient_email, subject, body, payload_json FROM {{%notification_outbox}} "
            . "WHERE request_id = :id AND event_type = 'request.completed'",
            [':id' => $fixture['requestId']],
        )->queryOne();
        self::assertSame($fixture['initiatorEmail'], $notification['recipient_email']);
        self::assertSame('Испытания завершены', $notification['subject']);
        self::assertStringContainsString('Служба безопасности согласовала заключение', $notification['body']);
        $payload = is_array($notification['payload_json'])
            ? $notification['payload_json']
            : json_decode((string) $notification['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['label' => 'отчёт', 'documentVersionId' => $fixture['reportVersionId']],
            ['label' => 'заключение', 'documentVersionId' => $fixture['opinionVersionId']],
        ], $payload['documentLinks']);
    }

    public function testReturnRestoresInProgressAndNotifiesOnlyExecutorWithReason(): void
    {
        $fixture = $this->fixture('return');
        $result = $this->decide($fixture, 'return', '  Уточнить вывод  ');

        self::assertSame('in_progress', $result['status']);
        self::assertSame('  Уточнить вывод  ', $this->scalar(
            "SELECT reason FROM {{%request_transitions}} WHERE request_id = :id AND action = 'security_return'",
            [':id' => $fixture['requestId']],
        ));
        $notification = $this->db()->createCommand(
            'SELECT event_type, recipient_email, subject, body FROM {{%notification_outbox}} '
            . 'WHERE request_id = :id ORDER BY id DESC LIMIT 1',
            [':id' => $fixture['requestId']],
        )->queryOne();
        self::assertSame('request.returned', $notification['event_type']);
        self::assertSame($fixture['executorEmail'], $notification['recipient_email']);
        self::assertSame('Заявка возвращена на доработку', $notification['subject']);
        self::assertStringContainsString('Причина:   Уточнить вывод  ', $notification['body']);
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.completed'",
            [':id' => $fixture['requestId']],
        ));
    }

    public function testAuthorizationActiveRoleInvalidStatusAndStaleVersionKeepRuleSemantics(): void
    {
        $fixture = $this->fixture('denials');
        $cases = [
            [$this->createUser('security.denials.employee', 'Сотрудник'), true, 'SEC-001'],
            [$this->inactiveOfficer('security.denials.inactive'), true, 'AUTH-003'],
            [$fixture['officerId'], false, 'SEC-001'],
        ];
        foreach ($cases as [$actorId, $securityStatus, $ruleId]) {
            $this->db()->createCommand()->update('{{%requests}}', [
                'status' => $securityStatus ? 'security_review' : 'in_progress',
            ], ['id' => $fixture['requestId']])->execute();
            try {
                $this->useCase()->execute(new DecideSecurityCommand(
                    $fixture['requestId'],
                    $actorId,
                    'approve',
                    null,
                    $fixture['lockVersion'],
                ));
                self::fail('Expected denied security decision.');
            } catch (SecurityDecisionDenied $error) {
                self::assertSame($ruleId, $error->ruleId);
            }
        }

        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'security_review'], ['id' => $fixture['requestId']])->execute();
        $this->expectException(ConcurrentRequestModification::class);
        $this->useCase()->execute(new DecideSecurityCommand(
            $fixture['requestId'],
            $fixture['officerId'],
            'approve',
            null,
            $fixture['lockVersion'] + 1,
        ));
    }

    public function testMissingRequestAndLockedOpinionStateRemainDistinct(): void
    {
        $fixture = $this->fixture('missing-opinion');
        try {
            $this->useCase()->execute(new DecideSecurityCommand(
                PHP_INT_MAX,
                $fixture['officerId'],
                'approve',
                null,
                1,
            ));
            self::fail('Expected missing request.');
        } catch (RequestNotFound) {
        }

        $this->db()->createCommand()->insert('{{%security_checks}}', [
            'request_id' => $fixture['requestId'],
            'expert_opinion_id' => $fixture['opinionId'],
            'officer_id' => $fixture['officerId'],
            'decision' => 'return',
            'reason' => 'Предыдущая проверка',
            'created_at' => Clock::now(),
        ])->execute();
        $this->expectExceptionMessage('Current expert opinion not found or already checked.');
        $this->decide($fixture, 'approve');
    }

    public function testRejectedAuditKeepsExistingReferenceSemantics(): void
    {
        $fixture = $this->fixture('rejected-audit');
        $useCase = $this->useCase();
        $command = new DecideSecurityCommand(
            $fixture['requestId'],
            $fixture['officerId'],
            'approve',
            null,
            $fixture['lockVersion'],
        );
        $useCase->recordRejected($command, 'SEC-001');
        $useCase->recordRejected(new DecideSecurityCommand(
            PHP_INT_MAX,
            $fixture['officerId'],
            'approve',
            null,
            1,
        ), 'SEC-001');

        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id "
            . "AND event_type = 'request.security_decision_rejected' AND rule_id = 'SEC-001'",
            [':id' => $fixture['requestId']],
        ));
    }

    /**
     * @param array<string, int|string> $fixture
     * @return array{requestId: int, decision: string, status: string, lockVersion: int}
     */
    private function decide(array $fixture, string $decision, ?string $reason = null): array
    {
        return $this->useCase()->execute(new DecideSecurityCommand(
            (int) $fixture['requestId'],
            (int) $fixture['officerId'],
            $decision,
            $reason,
            (int) $fixture['lockVersion'],
        ))->toArray();
    }

    private function useCase(): DecideSecurity
    {
        return new DecideSecurity(new SecurityDecisionPersistenceAdapter($this->db()));
    }

    private function inactiveOfficer(string $login): int
    {
        $id = $this->createUser($login, 'Неактивный сотрудник СБ', isActive: false);
        $this->grantRole($id, 'security_officer');
        return $id;
    }

    /** @return array<string, int|string> */
    private function fixture(string $marker): array
    {
        $initiatorEmail = "security.{$marker}.initiator@example.invalid";
        $executorEmail = "security.{$marker}.executor@example.invalid";
        $initiator = $this->createUser("security.{$marker}.initiator", 'Инициатор', $initiatorEmail);
        $executor = $this->createUser("security.{$marker}.executor", 'Исполнитель', $executorEmail);
        $expert = $this->createUser("security.{$marker}.expert", 'Эксперт');
        $officer = $this->createUser("security.{$marker}.officer", 'Сотрудник СБ');
        $this->grantRole($officer, 'security_officer');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "Security {$marker}", 'manufacturer' => 'Завод', 'supplier' => 'Поставщик',
            'sampleQuantity' => 1, 'testMethod' => 'Методика',
        ]);
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($initiator))->toArray();
        $requestId = (int) $request['id'];
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'security_review'], ['id' => $requestId])->execute();
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId, 'assignment_type' => 'executor', 'user_id' => $executor,
            'assigned_by' => $officer, 'valid_from' => Clock::now(),
        ])->execute();
        $reportVersionId = $this->documentVersion($requestId, $executor, 'report', 'report.pdf', 'a');
        $opinionVersionId = $this->documentVersion($requestId, $expert, 'opinion', 'opinion.pdf', 'b');
        $this->db()->createCommand()->insert('{{%expert_opinions}}', [
            'request_id' => $requestId, 'revision' => 1, 'expert_id' => $expert,
            'body' => 'Заключение', 'document_version_id' => $opinionVersionId, 'created_at' => Clock::now(),
        ])->execute();

        return [
            'requestId' => $requestId, 'lockVersion' => (int) $request['lock_version'], 'officerId' => $officer,
            'opinionId' => (int) $this->db()->getLastInsertID(), 'reportVersionId' => $reportVersionId,
            'opinionVersionId' => $opinionVersionId, 'initiatorEmail' => $initiatorEmail, 'executorEmail' => $executorEmail,
        ];
    }

    private function documentVersion(int $requestId, int $actorId, string $type, string $name, string $key): int
    {
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId, 'document_type' => $type, 'title' => $name,
            'created_by' => $actorId, 'created_at' => Clock::now(),
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId, 'version' => 1, 'storage_key' => str_repeat($key, 64),
            'original_name' => $name, 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'sha256' => str_repeat($key, 64), 'uploaded_by' => $actorId, 'created_at' => Clock::now(),
        ])->execute();
        return (int) $this->db()->getLastInsertID();
    }
}
