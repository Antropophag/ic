<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Application\Request\CreateRequestInput;
use App\Application\Request\Port\RequestLifecycleGateway;
use App\Http\Controller\RequestController;
use App\Infrastructure\Persistence\Request\RequestLifecyclePersistenceAdapter;
use App\Infrastructure\Request\RequestRepository;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Request;

final class RequestLifecycleHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testStartSuccessKeepsResponseShape(): void
    {
        [$requestId, $lockVersion, $managerId] = $this->fixture('start');

        $response = $this->controller(['lockVersion' => $lockVersion], $managerId)->actionStart($requestId);

        self::assertSame(['requestId', 'status', 'lockVersion', 'startedAt'], array_keys($response));
        self::assertSame($requestId, $response['requestId']);
        self::assertSame('in_progress', $response['status']);
        self::assertSame($lockVersion + 1, $response['lockVersion']);
    }

    public function testSuspendValidationKeeps422Shape(): void
    {
        $response = $this->controller(['reason' => '   ', 'lockVersion' => 0], null)->actionSuspend(10);

        self::assertSame(['reason', 'lockVersion'], array_keys($response['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testDeniedActorKeeps403AndDeniedAudit(): void
    {
        [$requestId, $lockVersion] = $this->fixture('denied');
        $actorId = $this->createUser('lifecycle.http.denied.actor', 'Обычный сотрудник');

        try {
            $this->controller(['lockVersion' => $lockVersion], $actorId)->actionStart($requestId);
            self::fail('Expected forbidden response.');
        } catch (ForbiddenHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND actor_id = :actor "
                . "AND event_type = 'request.start_denied' AND rule_id = 'WF-004'",
                [':id' => $requestId, ':actor' => $actorId],
            ));
        }
    }

    public function testMissingRequestKeeps404(): void
    {
        $managerId = $this->createUser('lifecycle.http.missing.manager', 'Руководитель');
        $this->grantRole($managerId, 'ic_manager');

        $this->expectException(NotFoundHttpException::class);
        $this->controller(['lockVersion' => 1], $managerId)->actionStart(PHP_INT_MAX);
    }

    public function testStaleStartKeeps409AndDeniedAudit(): void
    {
        [$requestId, $lockVersion, $managerId] = $this->fixture('stale');

        try {
            $this->controller(['lockVersion' => $lockVersion + 1], $managerId)->actionStart($requestId);
            self::fail('Expected conflict response.');
        } catch (ConflictHttpException) {
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id "
                . "AND event_type = 'request.start_denied' AND rule_id = 'WF-003'",
                [':id' => $requestId],
            ));
        }
    }

    /** @return array{int, int, int} */
    private function fixture(string $marker): array
    {
        $initiatorId = $this->createUser("lifecycle.http.{$marker}.initiator", 'Инициатор');
        $managerId = $this->createUser("lifecycle.http.{$marker}.manager", 'Руководитель');
        $this->grantRole($managerId, 'ic_manager');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "HTTP lifecycle {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $request = (new RequestRepository($this->db()))->create($input, $initiatorId);
        return [(int) $request['id'], (int) $request['lock_version'], $managerId];
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body, ?int $actorId): RequestController
    {
        $application = new Application([
            'id' => 'request-lifecycle-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'container' => [
                'definitions' => [
                    RequestLifecycleGateway::class => fn () => new RequestLifecyclePersistenceAdapter($this->db()),
                ],
            ],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'lifecycle-http-test'],
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
