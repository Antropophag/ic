<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller;

use App\Http\Controller\RequestController;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\db\Command;
use yii\db\Connection;
use yii\base\InlineAction;
use yii\web\Application;
use yii\web\ConflictHttpException;
use yii\web\Request;

final class RequestControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testStartReturnsExistingValidationContract(): void
    {
        $controller = $this->controllerWithBody([]);

        self::assertSame(
            ['errors' => ['lockVersion' => ['Lock Version cannot be blank.']]],
            $controller->actionStart(10),
        );
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testRejectReturnsErrorsForItsOwnInputFields(): void
    {
        $controller = $this->controllerWithBody([
            'lockVersion' => 0,
            'reason' => str_repeat('a', 5001),
        ]);

        $response = $controller->actionReject(10);

        self::assertSame(['reason', 'lockVersion'], array_keys($response['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testDeleteReportRequiresReason(): void
    {
        $controller = $this->controllerWithBody(['lockVersion' => 1]);

        self::assertSame(
            ['errors' => ['reason' => ['Reason cannot be blank.']]],
            $controller->actionDeleteReport(10),
        );
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testSuspendRequiresReason(): void
    {
        $controller = $this->controllerWithBody(['lockVersion' => 1]);

        self::assertSame(
            ['errors' => ['reason' => ['Reason cannot be blank.']]],
            $controller->actionSuspend(10),
        );
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testRejectRequiresReason(): void
    {
        $controller = $this->controllerWithBody(['lockVersion' => 1]);

        self::assertSame(
            ['errors' => ['reason' => ['Reason cannot be blank.']]],
            $controller->actionReject(10),
        );
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testWithdrawRequiresReason(): void
    {
        $controller = $this->controllerWithBody(['lockVersion' => 1]);

        self::assertSame(
            ['errors' => ['reason' => ['Reason cannot be blank.']]],
            $controller->actionWithdraw(10),
        );
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    public function testArchivedMutationGuardChecksStringRouteId(): void
    {
        $controller = $this->controllerWithBody([]);
        $command = $this->createMock(Command::class);
        $command->expects(self::once())->method('queryScalar')->willReturn('1');
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('createCommand')->willReturn($command);
        Yii::$app->set('db', $db);
        $action = new InlineAction('start', $controller, 'actionStart');

        $this->expectException(ConflictHttpException::class);
        $controller->bindActionParams($action, ['id' => '10']);
    }

    /** @param array<string, mixed> $body */
    private function controllerWithBody(array $body): RequestController
    {
        $application = new Application([
            'id' => 'request-controller-test',
            'basePath' => dirname(__DIR__, 4),
            'components' => [
                'request' => [
                    'class' => Request::class,
                    'cookieValidationKey' => 'request-controller-test',
                ],
            ],
        ]);
        $application->request->headers->set('Content-Type', 'application/json');
        $application->request->setRawBody(json_encode((object) $body, JSON_THROW_ON_ERROR));

        return new RequestController('request', $application);
    }
}
