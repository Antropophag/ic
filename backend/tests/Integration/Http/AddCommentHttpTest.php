<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Application\Request\Port\RequestCommentGateway;
use App\Http\Controller\RequestController;
use App\Http\Request\CreateRequest;
use App\Infrastructure\Persistence\Request\RequestCommentPersistenceAdapter;
use App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Request;

final class AddCommentHttpTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testValidationSuccessAndErrorContractsArePreserved(): void
    {
        [$requestId, $actorId] = $this->fixture('success');
        $response = $this->controller(['body' => '  HTTP комментарий  '], $actorId)->actionAddComment($requestId);
        self::assertSame(201, Yii::$app->response->statusCode);
        self::assertSame(['id', 'body', 'createdAt', 'authorName'], array_keys($response));
        self::assertSame('HTTP комментарий', $response['body']);

        $errors = $this->controller(['body' => '   '], $actorId)->actionAddComment($requestId);
        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertSame(['body'], array_keys($errors['errors']));
    }

    public function testMissingAndDeniedStatusMappingsArePreserved(): void
    {
        [$requestId, $actorId] = $this->fixture('errors');
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'completed'], ['id' => $requestId])->execute();
        try {
            $this->controller(['body' => 'Комментарий'], $actorId)->actionAddComment($requestId);
            self::fail('Expected conflict response.');
        } catch (ConflictHttpException $error) {
            self::assertSame(409, $error->statusCode);
        }

        $this->expectException(NotFoundHttpException::class);
        $this->controller(['body' => 'Комментарий'], $actorId)->actionAddComment(PHP_INT_MAX);
    }

    /** @return array{int, int} */
    private function fixture(string $marker): array
    {
        $actorId = $this->createUser("comment.http.{$marker}", 'Автор');
        $input = new CreateRequest();
        $input->setAttributes([
            'productName' => "HTTP comment {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $result = (new \App\Application\Request\UseCase\CreateRequest(
            new RequestCreationPersistenceAdapter($this->db()),
        ))->execute($input->toCommand($actorId));
        return [(int) $result->toArray()['id'], $actorId];
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body, int $actorId): RequestController
    {
        Yii::$app?->errorHandler->unregister();
        $application = new Application([
            'id' => 'add-comment-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'container' => ['definitions' => [
                RequestCommentGateway::class => fn () => new RequestCommentPersistenceAdapter($this->db()),
            ]],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'add-comment-http-test'],
            ],
        ]);
        $application->request->headers->set('Content-Type', 'application/json');
        $application->request->headers->set('X-Test-User-ID', (string) $actorId);
        $application->request->setRawBody(json_encode((object) $body, JSON_THROW_ON_ERROR));
        return new RequestController('request', $application);
    }
}
