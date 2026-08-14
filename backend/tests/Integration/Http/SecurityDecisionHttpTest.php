<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Application\Request\Port\SecurityDecisionGateway;
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
            'document_id' => $documentId, 'version' => 1, 'storage_key' => str_repeat('c', 64),
            'original_name' => 'opinion.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'sha256' => str_repeat('c', 64), 'uploaded_by' => $expert, 'created_at' => Clock::now(),
        ])->execute();
        $versionId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%expert_opinions}}', [
            'request_id' => $requestId, 'revision' => 1, 'expert_id' => $expert,
            'body' => 'Заключение', 'document_version_id' => $versionId, 'created_at' => Clock::now(),
        ])->execute();
        return [$requestId, (int) $request['lock_version'], $officer];
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body, ?int $actorId): RequestController
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        $application = new Application([
            'id' => 'security-decision-http-test', 'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'container' => ['definitions' => [
                SecurityDecisionGateway::class => fn () => new SecurityDecisionPersistenceAdapter($this->db()),
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
