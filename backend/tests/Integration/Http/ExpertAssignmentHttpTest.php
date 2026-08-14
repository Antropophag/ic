<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Application\Request\Command\AssignExpertCommand;
use App\Application\Request\CreateRequestInput;
use App\Application\Request\Port\ExpertAssignmentGateway;
use App\Application\Request\UseCase\AssignExpert;
use App\Http\Controller\RequestController;
use App\Infrastructure\Persistence\Request\ExpertAssignmentPersistenceAdapter;
use App\Infrastructure\Request\RequestRepository;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Request;

final class ExpertAssignmentHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testClaimAndReassignKeepResponseAuditHistoryAndNotificationContracts(): void
    {
        [$requestId, $lockVersion, $firstExpert, $secondExpert] = $this->fixture('success');
        $reportVersionId = $this->createReportVersion($requestId, $firstExpert);

        $claimed = $this->controller(['lockVersion' => $lockVersion], $firstExpert)->actionClaimExpert($requestId);
        self::assertSame(
            ['id', 'requestId', 'expertId', 'assignedBy', 'assignedAt', 'lockVersion'],
            array_keys($claimed),
        );
        self::assertSame($firstExpert, $claimed['expertId']);
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.expert_claimed'",
            [':id' => $requestId],
        ));

        $reassigned = $this->controller([
            'expertId' => $secondExpert,
            'lockVersion' => $claimed['lockVersion'],
        ], $firstExpert)->actionReassignExpert($requestId);
        self::assertSame($secondExpert, $reassigned['expertId']);
        self::assertSame($lockVersion + 2, $reassigned['lockVersion']);
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.expert_reassigned'",
            [':id' => $requestId],
        ));
        $outboxPayload = $this->scalar(
            "SELECT payload_json FROM {{%notification_outbox}} WHERE request_id = :id "
            . "AND event_type = 'request.expert_reassigned'",
            [':id' => $requestId],
        );
        $outboxPayload = is_array($outboxPayload)
            ? $outboxPayload
            : json_decode((string) $outboxPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            [['label' => 'отчёт', 'documentVersionId' => $reportVersionId]],
            $outboxPayload['documentLinks'],
        );

        $assignments = $this->db()->createCommand(
            "SELECT user_id, valid_to FROM {{%request_assignments}} WHERE request_id = :id "
            . "AND assignment_type = 'expert' ORDER BY id",
            [':id' => $requestId],
        )->queryAll();
        self::assertCount(2, $assignments);
        self::assertNotNull($assignments[0]['valid_to']);
        self::assertNull($assignments[1]['valid_to']);
        self::assertSame($secondExpert, (int) $assignments[1]['user_id']);

        $audits = $this->db()->createCommand(
            "SELECT event_type, rule_id, payload_json FROM {{%audit_events}} WHERE entity_id = :id "
            . "AND event_type IN ('request.expert_claimed', 'request.expert_reassigned') ORDER BY id",
            [':id' => $requestId],
        )->queryAll();
        self::assertSame(['request.expert_claimed', 'request.expert_reassigned'], array_column($audits, 'event_type'));
        self::assertSame(['WF-010', 'WF-011'], array_column($audits, 'rule_id'));
    }

    public function testValidationKeeps422Shapes(): void
    {
        $claim = $this->controller(['lockVersion' => 0], null)->actionClaimExpert(10);
        self::assertSame(['lockVersion'], array_keys($claim['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);

        $reassign = $this->controller(['expertId' => 0, 'lockVersion' => 0], null)->actionReassignExpert(10);
        self::assertSame(['expertId', 'lockVersion'], array_keys($reassign['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testDeniedAndStaleRequestsKeepHttpAndDeniedAuditContracts(): void
    {
        [$requestId, $lockVersion, $expert] = $this->fixture('denied');
        $employee = $this->createUser('expert.http.denied.employee', 'Сотрудник');
        try {
            $this->controller(['lockVersion' => $lockVersion], $employee)->actionClaimExpert($requestId);
            self::fail('Expected forbidden response.');
        } catch (ForbiddenHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND actor_id = :actor "
                . "AND event_type = 'request.expert_assignment_denied' AND rule_id = 'WF-010'",
                [':id' => $requestId, ':actor' => $employee],
            ));
        }

        try {
            $this->controller(['lockVersion' => $lockVersion + 1], $expert)->actionClaimExpert($requestId);
            self::fail('Expected conflict response.');
        } catch (ConflictHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND actor_id = :actor "
                . "AND event_type = 'request.expert_assignment_denied' AND rule_id = 'WF-003'",
                [':id' => $requestId, ':actor' => $expert],
            ));
        }
    }

    public function testMissingRequestAndTargetKeep404(): void
    {
        $expert = $this->createUser('expert.http.missing.expert', 'Эксперт');
        $this->grantRole($expert, 'expert');
        try {
            $this->controller(['lockVersion' => 1], $expert)->actionClaimExpert(PHP_INT_MAX);
            self::fail('Expected missing request.');
        } catch (NotFoundHttpException) {
        }

        [$requestId, $lockVersion, $current] = $this->fixture('missing-target');
        $claimed = (new AssignExpert(new ExpertAssignmentPersistenceAdapter($this->db())))->execute(
            AssignExpertCommand::claim($requestId, $lockVersion, $current),
        );
        $this->expectException(NotFoundHttpException::class);
        $this->controller(['expertId' => PHP_INT_MAX, 'lockVersion' => $claimed->lockVersion], $current)
            ->actionReassignExpert($requestId);
    }

    /** @return array{int, int, int, int} */
    private function fixture(string $marker): array
    {
        $initiator = $this->createUser("expert.http.{$marker}.initiator", 'Инициатор');
        $firstExpert = $this->createUser("expert.http.{$marker}.first", 'Первый эксперт');
        $secondExpert = $this->createUser("expert.http.{$marker}.second", 'Второй эксперт');
        $this->grantRole($firstExpert, 'expert');
        $this->grantRole($secondExpert, 'expert');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "HTTP expert assignment {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $request = (new RequestRepository($this->db()))->create($input, $initiator);
        $this->db()->createCommand()->update(
            '{{%requests}}',
            ['status' => 'opinion_preparation'],
            ['id' => $request['id']],
        )->execute();
        return [(int) $request['id'], (int) $request['lock_version'], $firstExpert, $secondExpert];
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body, ?int $actorId): RequestController
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        $application = new Application([
            'id' => 'expert-assignment-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'container' => ['definitions' => [
                ExpertAssignmentGateway::class => fn () => new ExpertAssignmentPersistenceAdapter($this->db()),
            ]],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'expert-assignment-http-test'],
            ],
        ]);
        $application->request->headers->set('Content-Type', 'application/json');
        if ($actorId !== null) {
            $application->request->headers->set('X-Test-User-ID', (string) $actorId);
        }
        $application->request->setRawBody(json_encode((object) $body, JSON_THROW_ON_ERROR));
        return new RequestController('request', $application);
    }

    private function createReportVersion(int $requestId, int $userId): int
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'document_type' => 'report',
            'title' => 'Отчёт',
            'created_by' => $userId,
            'created_at' => $now,
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId,
            'version' => 1,
            'storage_key' => bin2hex(random_bytes(20)),
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => hash('sha256', 'report'),
            'uploaded_by' => $userId,
            'created_at' => $now,
        ])->execute();
        return (int) $this->db()->getLastInsertID();
    }
}
