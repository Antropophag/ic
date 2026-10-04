<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Http\Controller\RequestController;
use App\Infrastructure\Clock;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\Request;

final class RequestDashboardHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testEmployeeResponseContainsAggregatesButNoExecutorIdentity(): void
    {
        $employeeId = $this->createUser('dashboard.http.employee', 'Обычный сотрудник');
        $managerId = $this->createUser('dashboard.http.manager', 'Руководитель');
        $executorId = $this->createUser('dashboard.http.executor', 'Секретное Имя Исполнителя');
        $this->grantRole($managerId, 'ic_manager');
        $this->grantRole($executorId, 'ic_executor');
        $requestId = $this->createOperationalRequest($employeeId);
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $executorId,
            'assigned_by' => $managerId,
            'valid_from' => Clock::now(),
        ])->execute();

        $response = $this->controller($employeeId)->actionDashboard();
        $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertGreaterThanOrEqual(1, $response['operational_summary']['active']);
        foreach ($response['operational_summary']['directions'] as $direction) {
            self::assertSame([], $direction['executors']);
        }
        self::assertStringNotContainsString('Секретное Имя Исполнителя', $json);
        self::assertStringNotContainsString('"user_id"', $json);
        self::assertStringNotContainsString('"display_name"', $json);
    }

    private function createOperationalRequest(int $initiatorId): int
    {
        $now = Clock::now();
        $this->db()->createCommand()->insert('{{%requests}}', [
            'number' => random_int(900000000, 999999999),
            'initiator_id' => $initiatorId,
            'department_name' => 'Тестовое подразделение',
            'status' => 'registered',
            'product_name' => 'HTTP dashboard privacy fixture',
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sample_quantity' => 1,
            'test_method' => 'Методика',
            'revision' => 1,
            'lock_version' => 1,
            'color' => 'blue',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db()->getLastInsertID();
    }

    private function controller(int $actorId): RequestController
    {
        $application = new Application([
            'id' => 'request-dashboard-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'request-dashboard-http-test'],
            ],
        ]);
        $application->request->headers->set('X-Test-User-ID', (string) $actorId);
        return new RequestController('request', $application);
    }
}
