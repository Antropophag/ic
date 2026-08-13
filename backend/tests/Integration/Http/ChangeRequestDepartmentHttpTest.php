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

final class ChangeRequestDepartmentHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testValidationErrorsKeepTheExisting422Shape(): void
    {
        $controller = $this->controller(['department' => '   ', 'lockVersion' => 0], null);

        $response = $controller->actionChangeDepartment(10);

        self::assertSame(['department', 'lockVersion'], array_keys($response['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testSuccessKeepsTheExistingResponseShape(): void
    {
        [$requestId, $lockVersion, $administratorId] = $this->fixture('success');
        $controller = $this->controller([
            'department' => '  Новое подразделение  ',
            'lockVersion' => $lockVersion,
        ], $administratorId);

        $response = $controller->actionChangeDepartment($requestId);

        self::assertSame($requestId, $response['id']);
        self::assertSame('Новое подразделение', $response['department']);
        self::assertSame($lockVersion + 1, $response['lock_version']);
        self::assertSame([
            'id', 'number', 'legacy_id', 'initiator_id', 'status', 'product_name', 'manufacturer',
            'supplier', 'sample_quantity', 'legacy_sample_quantity_raw', 'test_method', 'revision',
            'lock_version', 'color', 'department', 'created_at', 'updated_at',
        ], array_keys($response));
    }

    public function testDeniedActorKeeps403(): void
    {
        [$requestId, $lockVersion] = $this->fixture('denied');
        $actorId = $this->createUser('department.http.denied.actor', 'Обычный сотрудник');
        $controller = $this->controller([
            'department' => 'Новое подразделение',
            'lockVersion' => $lockVersion,
        ], $actorId);

        $this->expectException(ForbiddenHttpException::class);
        $controller->actionChangeDepartment($requestId);
    }

    public function testMissingRequestKeeps404(): void
    {
        $administratorId = $this->createUser('department.http.missing.admin', 'Администратор');
        $this->grantRole($administratorId, 'administrator');
        $controller = $this->controller([
            'department' => 'Новое подразделение',
            'lockVersion' => 1,
        ], $administratorId);

        $this->expectException(NotFoundHttpException::class);
        $controller->actionChangeDepartment(PHP_INT_MAX);
    }

    public function testStaleVersionKeeps409(): void
    {
        [$requestId, $lockVersion, $administratorId] = $this->fixture('stale');
        $controller = $this->controller([
            'department' => 'Новое подразделение',
            'lockVersion' => $lockVersion + 1,
        ], $administratorId);

        $this->expectException(ConflictHttpException::class);
        $controller->actionChangeDepartment($requestId);
    }

    /** @return array{int, int, int} */
    private function fixture(string $marker): array
    {
        $initiatorId = $this->createUser("department.http.{$marker}.initiator", 'Инициатор');
        $administratorId = $this->createUser("department.http.{$marker}.admin", 'Администратор');
        $this->grantRole($administratorId, 'administrator');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "HTTP department {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $request = (new RequestRepository($this->db()))->create($input, $initiatorId);
        return [(int) $request['id'], (int) $request['lock_version'], $administratorId];
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body, ?int $actorId): RequestController
    {
        $application = new Application([
            'id' => 'change-request-department-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'department-http-test'],
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
