<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller;

use App\Application\Ai\AnalyzeTechnicalSpecification;
use App\Application\Ai\CreateTestSpecificationDraft;
use App\Application\Ai\AiRequestLifecycle;
use App\Application\Ai\TechnicalSpecificationDocumentPort;
use App\Application\Ai\TechnicalSpecificationCandidate;
use App\Http\Controller\AiRequestController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Ai\FakeConversations;
use Tests\Unit\Application\Ai\FakeLiza;
use Tests\Unit\Application\Ai\FakeTechnicalSpecificationDocuments;
use Yii;
use yii\db\Command;
use yii\db\Connection;
use yii\web\Application;
use yii\web\Request;
use yii\web\UnprocessableEntityHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;

final class AiRequestControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testAnalyzeAndDraftReturnIndependentResults(): void
    {
        $controller = $this->controller();

        Yii::$app->request->setBodyParams([]);
        self::assertSame('completed', $controller->actionAnalyze(7)['status']);
        self::assertSame('completed', $controller->actionDraft(7)['status']);
    }

    public function testInvalidDocumentVersionIsRejectedByBothActions(): void
    {
        $controller = $this->controller();
        Yii::$app->request->setBodyParams(['documentVersionId' => 'invalid']);

        foreach (['actionAnalyze', 'actionDraft'] as $action) {
            try {
                $controller->{$action}(7);
                self::fail("{$action} must reject an invalid document version.");
            } catch (UnprocessableEntityHttpException $error) {
                self::assertSame('Некорректная версия документа.', $error->getMessage());
            }
        }
    }

    public function testKeepsDefaultControllerBehaviorsExceptRateLimiter(): void
    {
        $controller = $this->controller();

        self::assertArrayNotHasKey('rateLimiter', $controller->behaviors());
    }

    public function testMapsApplicationFailuresToHttpErrors(): void
    {
        $controller = $this->controller();
        Yii::$app->request->setBodyParams(['documentVersionId' => 99]);
        try {
            $controller->actionAnalyze(7);
            self::fail('Unavailable document must fail.');
        } catch (UnprocessableEntityHttpException $error) {
            self::assertStringContainsString('недоступен', mb_strtolower($error->getMessage()));
        }

        $liza = new FakeLiza();
        $liza->fail = true;
        $controller = $this->controller($liza);
        Yii::$app->request->setBodyParams([]);
        try {
            $controller->actionDraft(7);
            self::fail('LIZA failure must fail.');
        } catch (HttpException $error) {
            self::assertSame(503, $error->statusCode);
        }

        $documents = new class implements TechnicalSpecificationDocumentPort {
            public function candidates(int $requestId, int $actorId): array
            {
                throw new \App\Domain\Request\RequestNotFound('Request not found');
            }

            public function file(int $requestId, int $versionId, int $actorId): \App\Application\Ai\TechnicalSpecificationFile
            {
                throw new \LogicException('Not reached');
            }
        };
        $controller = $this->controller(documents: $documents);
        try {
            $controller->actionAnalyze(7);
            self::fail('Missing request must fail.');
        } catch (NotFoundHttpException $error) {
            self::assertSame('Request not found', $error->getMessage());
        }
    }

    public function testDelegatesToSpecializedAiLifecycle(): void
    {
        $controller = $this->controller();
        Yii::$container->set(AiRequestLifecycle::class, new class implements AiRequestLifecycle {
            public function execute(
                int $actorId,
                string $method,
                string $route,
                string $key,
                string $requestHash,
                callable $operation,
                callable $statusCode,
                callable $location,
            ): array {
                return ['body' => $operation(), 'statusCode' => $statusCode(), 'location' => $location(), 'replayed' => false];
            }
        });
        $method = new \ReflectionMethod($controller, 'executeIdempotent');

        self::assertSame(
            ['body' => ['ok' => true], 'statusCode' => 202, 'location' => '/result', 'replayed' => false],
            $method->invoke(
                $controller,
                3,
                'POST',
                'ai/analyze',
                str_repeat('k', 16),
                'hash',
                static fn (): array => ['ok' => true],
                static fn (): int => 202,
                static fn (): string => '/result',
            ),
        );
    }

    private function controller(
        ?FakeLiza $liza = null,
        ?TechnicalSpecificationDocumentPort $documents = null,
    ): AiRequestController {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        $command = $this->createMock(Command::class);
        $command->method('queryOne')->willReturn(['isActive' => 1, 'lastActivityAt' => null]);
        $db = $this->createMock(Connection::class);
        $db->method('createCommand')->willReturn($command);
        $application = new Application([
            'id' => 'ai-request-controller-test',
            'basePath' => dirname(__DIR__, 4),
            'components' => [
                'db' => $db,
                'request' => [
                    'class' => Request::class,
                    'cookieValidationKey' => 'ai-request-controller-test',
                ],
            ],
        ]);
        $application->session->set('userId', 3);
        $documents ??= new FakeTechnicalSpecificationDocuments([
            new TechnicalSpecificationCandidate(
                11,
                'ТЗ.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                1,
            ),
        ]);
        $liza ??= new FakeLiza();
        $conversations = new FakeConversations();
        Yii::$container->set(AnalyzeTechnicalSpecification::class, new AnalyzeTechnicalSpecification(
            $documents,
            $liza,
            $conversations,
            true,
        ));
        Yii::$container->set(CreateTestSpecificationDraft::class, new CreateTestSpecificationDraft(
            $documents,
            $liza,
            $conversations,
            true,
        ));

        return new AiRequestController('ai-request', $application);
    }
}
