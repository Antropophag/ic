<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Application\Request\Port\SecurityDecisionGateway;
use App\Application\Request\SecurityDecisionSnapshot;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Http\Controller\RequestController;
use App\Infrastructure\Clock;
use App\Infrastructure\Persistence\Request\SecurityDecisionPersistenceAdapter;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Request;

final class SecurityDecisionHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testApproveKeepsResponseShape(): void
    {
        [$requestId, $lockVersion, $officerId] = $this->fixture('approve');

        $response = $this->controller([
            'decision' => 'approve', 'reason' => null, 'lockVersion' => $lockVersion,
        ], $officerId)->actionSecurityDecision($requestId);

        self::assertSame(['requestId', 'decision', 'status', 'lockVersion'], array_keys($response));
        self::assertSame([$requestId, 'approve', 'completed', $lockVersion + 1], array_values($response));
    }

    public function testApproveWithReasonReturns422(): void
    {
        [$requestId, $lockVersion, $officerId] = $this->fixture('approve-reason');

        $response = $this->controller([
            'decision' => 'approve', 'reason' => 'Согласовано', 'lockVersion' => $lockVersion,
        ], $officerId)->actionSecurityDecision($requestId);

        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertSame(['reason'], array_keys($response['errors']));
        self::assertSame('security_review', $this->scalar(
            'SELECT status FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        ));
    }

    public function testReturnRequiresAndTrimsReasonWith422Contract(): void
    {
        $invalid = $this->controller([
            'decision' => 'return', 'reason' => '   ', 'lockVersion' => 1,
        ], null)->actionSecurityDecision(10);
        self::assertSame(['reason'], array_keys($invalid['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);

        [$requestId, $lockVersion, $officerId] = $this->fixture('trim');
        $this->controller([
            'decision' => 'return', 'reason' => '  Уточнить вывод  ', 'lockVersion' => $lockVersion,
        ], $officerId)->actionSecurityDecision($requestId);
        self::assertSame('Уточнить вывод', $this->scalar(
            "SELECT reason FROM {{%request_transitions}} WHERE request_id = :id AND action = 'security_return'",
            [':id' => $requestId],
        ));
    }

    public function testMissingDeniedAndStaleKeep404403409ContractsAndDeniedAudit(): void
    {
        [$requestId, $lockVersion, $officerId] = $this->fixture('errors');
        try {
            $this->controller([
                'decision' => 'approve', 'lockVersion' => 1,
            ], $officerId)->actionSecurityDecision(PHP_INT_MAX);
            self::fail('Expected not found.');
        } catch (NotFoundHttpException) {
        }

        $employee = $this->createUser('security.http.errors.employee', 'Сотрудник');
        try {
            $this->controller([
                'decision' => 'approve', 'lockVersion' => $lockVersion,
            ], $employee)->actionSecurityDecision($requestId);
            self::fail('Expected forbidden.');
        } catch (ForbiddenHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND actor_id = :actor "
                . "AND event_type = 'request.security_decision_rejected' AND rule_id = 'SEC-001'",
                [':id' => $requestId, ':actor' => $employee],
            ));
        }

        try {
            $this->controller([
                'decision' => 'approve', 'lockVersion' => $lockVersion + 1,
            ], $officerId)->actionSecurityDecision($requestId);
            self::fail('Expected conflict.');
        } catch (ConflictHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND actor_id = :actor "
                . "AND event_type = 'request.security_decision_rejected' AND rule_id = 'WF-003'",
                [':id' => $requestId, ':actor' => $officerId],
            ));
        }
    }

    public function testMissingAndAlreadyReviewedOpinionReturn409WithoutPartialEffects(): void
    {
        foreach (['missing', 'reviewed'] as $state) {
            [$requestId, $lockVersion, $officerId] = $this->fixture("opinion-{$state}");
            if ($state === 'missing') {
                $this->db()->createCommand()->delete('{{%expert_opinions}}', ['request_id' => $requestId])->execute();
            } else {
                $opinionId = (int) $this->scalar(
                    'SELECT id FROM {{%expert_opinions}} WHERE request_id = :id',
                    [':id' => $requestId],
                );
                $this->db()->createCommand()->insert('{{%security_checks}}', [
                    'request_id' => $requestId, 'expert_opinion_id' => $opinionId,
                    'officer_id' => $officerId, 'decision' => 'return',
                    'reason' => 'Предыдущая проверка', 'created_at' => Clock::now(),
                ])->execute();
            }
            $initialChecks = (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id',
                [':id' => $requestId],
            );

            try {
                $this->controller([
                    'decision' => 'approve', 'lockVersion' => $lockVersion,
                ], $officerId)->actionSecurityDecision($requestId);
                self::fail("Expected {$state} opinion conflict.");
            } catch (ConflictHttpException) {
                $request = $this->db()->createCommand(
                    'SELECT status, lock_version FROM {{%requests}} WHERE id = :id',
                    [':id' => $requestId],
                )->queryOne();
                self::assertSame('security_review', $request['status']);
                self::assertSame($lockVersion, (int) $request['lock_version']);
                self::assertSame($initialChecks, (int) $this->scalar(
                    'SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id',
                    [':id' => $requestId],
                ));
                self::assertSame(0, (int) $this->scalar(
                    "SELECT COUNT(*) FROM {{%request_transitions}} WHERE request_id = :id AND action = 'security_approve'",
                    [':id' => $requestId],
                ));
                self::assertSame(0, (int) $this->scalar(
                    "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.security_decided'",
                    [':id' => $requestId],
                ));
                self::assertSame(0, (int) $this->scalar(
                    "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.completed'",
                    [':id' => $requestId],
                ));
                self::assertSame(1, (int) $this->scalar(
                    "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND actor_id = :actor "
                    . "AND event_type = 'request.security_decision_rejected' AND rule_id = 'WF-003'",
                    [':id' => $requestId, ':actor' => $officerId],
                ));
            }
        }
    }

    public function testDeniedAuditFailureKeepsForbiddenAndConflictResponsesAndIsLogged(): void
    {
        $cases = [
            [ForbiddenHttpException::class, [Role::Employee], 1],
            [ConflictHttpException::class, [Role::SecurityOfficer], 2],
        ];
        foreach ($cases as [$expectedException, $roles, $lockVersion]) {
            $gateway = $this->createMock(SecurityDecisionGateway::class);
            $gateway->method('transactional')->willReturnCallback(
                static fn (callable $operation): mixed => $operation(),
            );
            $gateway->method('decisionSnapshotForUpdate')->willReturn(new SecurityDecisionSnapshot(
                RequestStatus::SecurityReview,
                $lockVersion,
                true,
                $roles,
            ));
            $gateway->method('recordRejectedDecision')->willThrowException(
                new \RuntimeException('controlled denied audit failure'),
            );

            try {
                $this->controller([
                    'decision' => 'approve', 'lockVersion' => 1,
                ], 1, $gateway)->actionSecurityDecision(1);
                self::fail("Expected {$expectedException} response.");
            } catch (ForbiddenHttpException | ConflictHttpException $error) {
                self::assertInstanceOf($expectedException, $error);
                $messages = Yii::getLogger()->messages;
                self::assertNotEmpty(array_filter($messages, static fn (array $message): bool =>
                    $message[2] === 'App\\Http\\Controller\\RequestController::recordRejectedSecurityDecisionSafely'
                    && is_array($message[0])
                    && $message[0]['message'] === 'Не удалось записать аудит отклонённого решения СБ.'));
            }
        }
    }

    /** @return array{int, int, int} */
    private function fixture(string $marker): array
    {
        $initiator = $this->createUser("security.http.{$marker}.initiator", 'Инициатор');
        $expert = $this->createUser("security.http.{$marker}.expert", 'Эксперт');
        $officer = $this->createUser("security.http.{$marker}.officer", 'Сотрудник СБ');
        $this->grantRole($officer, 'security_officer');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "HTTP security {$marker}", 'manufacturer' => 'Завод', 'supplier' => 'Поставщик',
            'sampleQuantity' => 1, 'testMethod' => 'Методика',
        ]);
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($initiator))->toArray();
        $requestId = (int) $request['id'];
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'security_review'], ['id' => $requestId])->execute();
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId, 'document_type' => 'opinion', 'title' => 'opinion.pdf',
            'created_by' => $expert, 'created_at' => Clock::now(),
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId, 'version' => 1, 'storage_key' => hash('sha256', $marker),
            'original_name' => 'opinion.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'sha256' => hash('sha256', "opinion-{$marker}"), 'uploaded_by' => $expert, 'created_at' => Clock::now(),
        ])->execute();
        $versionId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%expert_opinions}}', [
            'request_id' => $requestId, 'revision' => 1, 'expert_id' => $expert,
            'body' => 'Заключение', 'document_version_id' => $versionId, 'created_at' => Clock::now(),
        ])->execute();
        return [$requestId, (int) $request['lock_version'], $officer];
    }

    /** @param array<string, mixed> $body */
    private function controller(
        array $body,
        ?int $actorId,
        ?SecurityDecisionGateway $gateway = null,
    ): RequestController {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        $application = new Application([
            'id' => 'security-decision-http-test', 'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'container' => ['definitions' => [
                SecurityDecisionGateway::class => fn () => $gateway ?? new SecurityDecisionPersistenceAdapter($this->db()),
            ]],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'security-http-test'],
            ],
        ]);
        $application->request->headers->set('Content-Type', 'application/json');
        if ($actorId !== null) {
            $application->request->headers->set('X-Test-User-ID', (string) $actorId);
        }
        $application->request->setRawBody(json_encode((object) $body, JSON_THROW_ON_ERROR));
        return new RequestController('request', $application);
    }
}
