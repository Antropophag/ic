<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Application\Request\Port\ExecutorAssignmentGateway;
use App\Http\Controller\RequestController;
use App\Infrastructure\Persistence\Request\ExecutorAssignmentPersistenceAdapter;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Request;

final class ExecutorAssignmentHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testSuccessKeepsResponseShape(): void
    {
        [$requestId, $lockVersion, $managerId, $executorId] = $this->fixture('success');

        $response = $this->controller([
            'executorId' => $executorId,
            'lockVersion' => $lockVersion,
        ], $managerId)->actionAssignExecutor($requestId);

        self::assertSame(
            ['id', 'requestId', 'executorId', 'assignedBy', 'assignedAt', 'lockVersion'],
            array_keys($response),
        );
        self::assertSame($requestId, $response['requestId']);
        self::assertSame($executorId, $response['executorId']);
        self::assertSame($lockVersion + 1, $response['lockVersion']);
    }

    public function testValidationKeeps422Shape(): void
    {
        $response = $this->controller(['executorId' => 0, 'lockVersion' => 0], null)->actionAssignExecutor(10);

        self::assertSame(['executorId', 'lockVersion'], array_keys($response['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testDeniedActorKeeps403AndDeniedAudit(): void
    {
        [$requestId, $lockVersion, , $executorId] = $this->fixture('denied');
        $actorId = $this->createUser('assignment.http.denied.actor', 'Обычный сотрудник');

        try {
            $this->controller(['executorId' => $executorId, 'lockVersion' => $lockVersion], $actorId)
                ->actionAssignExecutor($requestId);
            self::fail('Expected forbidden response.');
        } catch (ForbiddenHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND actor_id = :actor "
                . "AND event_type = 'request.executor_assignment_denied' AND rule_id = 'WF-001'",
                [':id' => $requestId, ':actor' => $actorId],
            ));
        }
    }

    public function testStaleVersionKeeps409AndDeniedAudit(): void
    {
        [$requestId, $lockVersion, $managerId, $executorId] = $this->fixture('stale');

        try {
            $this->controller(['executorId' => $executorId, 'lockVersion' => $lockVersion + 1], $managerId)
                ->actionAssignExecutor($requestId);
            self::fail('Expected conflict response.');
        } catch (ConflictHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id "
                . "AND event_type = 'request.executor_assignment_denied' AND rule_id = 'WF-003'",
                [':id' => $requestId],
            ));
        }
    }

    public function testMissingRequestKeeps404(): void
    {
        $managerId = $this->createUser('assignment.http.missing.manager', 'Руководитель');
        $executorId = $this->createUser('assignment.http.missing.executor', 'Исполнитель');
        $this->grantRole($managerId, 'ic_manager');
        $this->grantRole($executorId, 'ic_executor');

        $this->expectException(NotFoundHttpException::class);
        $this->controller(['executorId' => $executorId, 'lockVersion' => 1], $managerId)
            ->actionAssignExecutor(PHP_INT_MAX);
    }

    /** @return array{int, int, int, int} */
    private function fixture(string $marker): array
    {
        $initiatorId = $this->createUser("assignment.http.{$marker}.initiator", 'Инициатор');
        $managerId = $this->createUser("assignment.http.{$marker}.manager", 'Руководитель');
        $executorId = $this->createUser("assignment.http.{$marker}.executor", 'Исполнитель');
        $this->grantRole($managerId, 'ic_manager');
        $this->grantRole($executorId, 'ic_executor');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "HTTP assignment {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($initiatorId))->toArray();
        return [(int) $request['id'], (int) $request['lock_version'], $managerId, $executorId];
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body, ?int $actorId): RequestController
    {
        $application = new Application([
            'id' => 'executor-assignment-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'container' => [
                'definitions' => [
                    ExecutorAssignmentGateway::class => fn () => new ExecutorAssignmentPersistenceAdapter($this->db()),
                ],
            ],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'executor-assignment-http-test'],
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
