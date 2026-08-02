<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller;

use App\Http\Controller\RequestController;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\web\Application;
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
        $application->request->setBodyParams($body);

        return new RequestController('request', $application);
    }
}
