<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Http\Controller\AdminController;
use App\Http\Controller\AuthController;
use App\Http\Controller\RequestController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\web\Application;
use yii\web\BadRequestHttpException;
use yii\web\Request;
use yii\web\UnauthorizedHttpException;
use yii\web\UnsupportedMediaTypeHttpException;

final class JsonBodyContractTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    /** @param array<string, list<string>> $expectedErrors */
    #[DataProvider('controllerProvider')]
    public function testValidJsonObjectUsesExistingDtoValidation(
        string $controller,
        array $expectedErrors,
    ): void {
        $this->createApplication('{}', 'application/json');

        $response = $this->invokeAction($controller);

        self::assertSame(['errors' => $expectedErrors], $response);
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    /** @param array<string, list<string>> $expectedErrors */
    #[DataProvider('controllerProvider')]
    public function testJsonContentTypeWithCharsetIsAccepted(
        string $controller,
        array $expectedErrors,
    ): void {
        $this->createApplication('{}', 'application/json; charset=UTF-8');

        self::assertSame(['errors' => $expectedErrors], $this->invokeAction($controller));
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    #[DataProvider('invalidJsonBodyProvider')]
    public function testInvalidJsonBodyReturnsBadRequestForEveryController(
        string $controller,
        string $body,
        string $expectedMessage,
    ): void {
        $this->createApplication($body, 'application/json');

        try {
            $this->invokeAction($controller);
            self::fail('Expected invalid JSON body to be rejected.');
        } catch (BadRequestHttpException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }

    #[DataProvider('unsupportedContentTypeProvider')]
    public function testUnsupportedContentTypeReturnsSameResponseForEveryController(
        string $controller,
        ?string $contentType,
    ): void {
        $this->createApplication('{}', $contentType);

        try {
            $this->invokeAction($controller);
            self::fail('Expected unsupported Content-Type to be rejected.');
        } catch (UnsupportedMediaTypeHttpException $exception) {
            self::assertSame(415, $exception->statusCode);
            self::assertSame('Content-Type must be application/json.', $exception->getMessage());
        }
    }

    public function testAdminAuthorizationStillPrecedesBodyValidation(): void
    {
        $this->createApplication('{}', 'application/json');
        Yii::$app->request->headers->remove('X-Test-User-ID');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Authentication required');

        (new AdminController('admin', Yii::$app))->actionCreateUser();
    }

    public function testLoginRejectsNonStringCredentialsWithValidationResponse(): void
    {
        $this->createApplication('{"login":[1],"password":{"value":1}}', 'application/json');

        self::assertSame([
            'errors' => [
                'login' => ['Login must be a string.'],
                'password' => ['Password must be a string.'],
            ],
        ], (new AuthController('auth', Yii::$app))->actionLogin());
        self::assertSame(422, Yii::$app->response->statusCode);
    }

    /** @return iterable<string, array{string, array<string, list<string>>}> */
    public static function controllerProvider(): iterable
    {
        yield 'request' => [
            'request',
            ['lockVersion' => ['Lock Version cannot be blank.']],
        ];
        yield 'admin' => [
            'admin',
            ['adLogin' => ['Ad Login cannot be blank.']],
        ];
        yield 'auth' => [
            'auth',
            [
                'login' => ['Login cannot be blank.'],
                'password' => ['Password cannot be blank.'],
            ],
        ];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidJsonBodyProvider(): iterable
    {
        $cases = [
            'empty' => ['', 'Request body must not be empty.'],
            'whitespace' => [" \n\t", 'Request body must not be empty.'],
            'malformed' => ['{"lockVersion":', 'Malformed JSON request body.'],
            'empty array' => ['[]', 'Request body must be a JSON object.'],
            'array' => ['[{"lockVersion":1}]', 'Request body must be a JSON object.'],
            'string' => ['"value"', 'Request body must be a JSON object.'],
            'number' => ['123', 'Request body must be a JSON object.'],
            'boolean' => ['true', 'Request body must be a JSON object.'],
            'null' => ['null', 'Request body must be a JSON object.'],
        ];

        foreach (array_keys(iterator_to_array(self::controllerProvider())) as $controller) {
            foreach ($cases as $case => [$body, $message]) {
                yield $controller . ': ' . $case => [$controller, $body, $message];
            }
        }
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function unsupportedContentTypeProvider(): iterable
    {
        foreach (array_keys(iterator_to_array(self::controllerProvider())) as $controller) {
            yield $controller . ': missing' => [$controller, null];
            yield $controller . ': text/plain' => [$controller, 'text/plain'];
        }
    }

    private function createApplication(string $body, ?string $contentType): void
    {
        $actorId = $this->createUser(uniqid('json-contract-', true), 'JSON contract admin');
        $this->grantRole($actorId, 'administrator');

        $application = new Application([
            'id' => 'json-body-contract-test',
            'basePath' => dirname(__DIR__, 4),
            'params' => [
                'identityHeader' => 'X-Test-User-ID',
            ],
            'components' => [
                'db' => $this->db(),
                'request' => [
                    'class' => Request::class,
                    'cookieValidationKey' => 'json-body-contract-test',
                ],
            ],
        ]);
        if ($contentType !== null) {
            $application->request->headers->set('Content-Type', $contentType);
        }
        $application->request->headers->set('X-Test-User-ID', (string) $actorId);
        $application->request->setRawBody($body);
    }

    /** @return array<string, mixed> */
    private function invokeAction(string $controller): array
    {
        return match ($controller) {
            'request' => (new RequestController('request', Yii::$app))->actionStart(10),
            'admin' => (new AdminController('admin', Yii::$app))->actionCreateUser(),
            'auth' => (new AuthController('auth', Yii::$app))->actionLogin(),
            default => self::fail('Unknown controller case: ' . $controller),
        };
    }
}
