<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Application\Request\CreateRequestInput;
use App\Http\Controller\RequestController;
use App\Infrastructure\Request\RequestRepository;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Request;

final class SetRequestColorHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testValidationErrorsKeepTheExisting422Shape(): void
    {
        $controller = $this->controller(['color' => 'pink', 'lockVersion' => 0], null);

        $response = $controller->actionSetColor(10);

        self::assertSame(['color', 'lockVersion'], array_keys($response['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testSuccessKeepsTheExistingResponseShape(): void
    {
        [$requestId, $lockVersion, $managerId] = $this->fixture('success');
        $controller = $this->controller(['color' => 'orange', 'lockVersion' => $lockVersion], $managerId);

        self::assertSame([
            'requestId' => $requestId,
            'color' => 'orange',
            'lockVersion' => $lockVersion + 1,
        ], $controller->actionSetColor($requestId));
    }

    public function testDeniedActorKeeps403AndBestEffortAudit(): void
    {
        [$requestId, $lockVersion] = $this->fixture('denied');
        $actorId = $this->createUser('color.http.denied.actor', 'Обычный сотрудник');
        $controller = $this->controller(['color' => 'red', 'lockVersion' => $lockVersion], $actorId);

        try {
            $controller->actionSetColor($requestId);
            self::fail('Expected forbidden response.');
        } catch (ForbiddenHttpException $error) {
            self::assertSame(403, $error->statusCode);
        }
        $this->assertDeniedAudit($requestId, $actorId, 'WF-009');
    }

    public function testMissingRequestKeeps404(): void
    {
        $managerId = $this->createUser('color.http.missing.manager', 'Руководитель');
        $this->grantRole($managerId, 'ic_manager');
        $controller = $this->controller(['color' => 'blue', 'lockVersion' => 1], $managerId);

        $this->expectException(NotFoundHttpException::class);
        $controller->actionSetColor(PHP_INT_MAX);
    }

    public function testStaleVersionKeeps409AndBestEffortAudit(): void
    {
        [$requestId, $lockVersion, $managerId] = $this->fixture('stale');
        $controller = $this->controller(['color' => 'green', 'lockVersion' => $lockVersion + 1], $managerId);

        try {
            $controller->actionSetColor($requestId);
            self::fail('Expected conflict response.');
        } catch (ConflictHttpException $error) {
            self::assertSame(409, $error->statusCode);
        }
        $this->assertDeniedAudit($requestId, $managerId, 'WF-003');
    }

    /** @return array{int, int, int} */
    private function fixture(string $marker): array
    {
        $initiatorId = $this->createUser("color.http.{$marker}.initiator", 'Инициатор');
        $managerId = $this->createUser("color.http.{$marker}.manager", 'Руководитель');
        $this->grantRole($managerId, 'ic_manager');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "HTTP color {$marker}",
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
            'id' => 'set-request-color-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'set-request-color-http-test'],
            ],
        ]);
        $application->request->headers->set('Content-Type', 'application/json');
        if ($actorId !== null) {
            $application->request->headers->set('X-Test-User-ID', (string) $actorId);
        }
        $application->request->setRawBody(json_encode((object) $body, JSON_THROW_ON_ERROR));
        return new RequestController('request', $application);
    }

    private function assertDeniedAudit(int $requestId, int $actorId, string $ruleId): void
    {
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :request AND actor_id = :actor "
            . "AND event_type = 'request.color_mark_denied' AND rule_id = :rule",
            [':request' => $requestId, ':actor' => $actorId, ':rule' => $ruleId],
        ));
    }
}
