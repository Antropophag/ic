<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Application\Request\Port\RequestCancellationGateway;
use App\Http\Controller\RequestController;
use App\Infrastructure\Persistence\Request\RequestCancellationPersistenceAdapter;
use App\Infrastructure\Request\RequestRepository;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\Request;

final class RequestCancellationHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testRejectSuccessKeepsResponseShape(): void
    {
        [$requestId, $lockVersion, , $managerId] = $this->fixture('reject');

        $response = $this->controller(
            ['reason' => '  Не соответствует требованиям  ', 'lockVersion' => $lockVersion],
            $managerId,
        )->actionReject($requestId);

        self::assertSame(['requestId', 'status', 'lockVersion'], array_keys($response));
        self::assertSame([$requestId, 'rejected', $lockVersion + 1], array_values($response));
        self::assertSame('Не соответствует требованиям', $this->scalar(
            "SELECT reason FROM {{%request_transitions}} WHERE request_id = :id AND action = 'reject'",
            [':id' => $requestId],
        ));
    }

    public function testWithdrawSuccessKeepsResponseShape(): void
    {
        [$requestId, $lockVersion, $initiatorId] = $this->fixture('withdraw');

        $response = $this->controller(
            ['reason' => 'Больше не актуально', 'lockVersion' => $lockVersion],
            $initiatorId,
        )->actionWithdraw($requestId);

        self::assertSame(['requestId', 'status', 'lockVersion'], array_keys($response));
        self::assertSame([$requestId, 'withdrawn', $lockVersion + 1], array_values($response));
    }

    public function testCancellationValidationKeeps422Shape(): void
    {
        $response = $this->controller(['reason' => '   ', 'lockVersion' => 0], null)->actionReject(10);

        self::assertSame(['reason', 'lockVersion'], array_keys($response['errors']));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    /** @return array{int, int, int, int} */
    private function fixture(string $marker): array
    {
        $initiatorId = $this->createUser("cancel.http.{$marker}.initiator", 'Инициатор');
        $managerId = $this->createUser("cancel.http.{$marker}.manager", 'Руководитель');
        $this->grantRole($managerId, 'ic_manager');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "HTTP cancellation {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($initiatorId))->toArray();

        return [(int) $request['id'], (int) $request['lock_version'], $initiatorId, $managerId];
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body, ?int $actorId): RequestController
    {
        $application = new Application([
            'id' => 'request-cancellation-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'container' => ['definitions' => [
                RequestCancellationGateway::class => fn () => new RequestCancellationPersistenceAdapter($this->db()),
            ]],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'cancellation-http-test'],
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
