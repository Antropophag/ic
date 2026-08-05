<?php

declare(strict_types=1);

namespace App\Http\Controller;

use JsonException;
use App\Infrastructure\Http\IdempotencyConflict;
use App\Infrastructure\Http\IdempotencyStore;
use App\Infrastructure\Http\RequestFingerprint;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Document\DocumentStorage;
use Yii;
use yii\base\Model;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;
use yii\web\UnsupportedMediaTypeHttpException;
use yii\web\ConflictHttpException;
use yii\web\UnprocessableEntityHttpException;

abstract class ApiController extends Controller
{
    /** @param array<string, mixed> $params */
    public function runAction($id, $params = []): mixed
    {
        $request = Yii::$app->request;
        if ($request->method !== 'POST' || !$this->requiresIdempotency()) {
            return parent::runAction($id, $params);
        }

        if (!$request->validateCsrfToken()) {
            throw new BadRequestHttpException(Yii::t('yii', 'Unable to verify your data submission.'));
        }
        $actorId = (new CurrentUser(Yii::$app->db))->id($request);
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if (!preg_match(IdempotencyStore::KEY_PATTERN, $key)) {
            throw new UnprocessableEntityHttpException(
                'Idempotency-Key обязателен и должен содержать 16–128 безопасных ASCII-символов.',
            );
        }
        $storageCheckpoint = DocumentStorage::writeCheckpoint();
        try {
            $result = (new IdempotencyStore(Yii::$app->db))->execute(
                $actorId,
                $request->method,
                $request->pathInfo,
                $key,
                RequestFingerprint::fromRequest($request, $_POST, $_FILES),
                fn (): mixed => parent::runAction($id, $params),
                fn (): int => Yii::$app->response->statusCode,
                fn (): ?string => Yii::$app->response->headers->get('Location'),
            );
        } catch (IdempotencyConflict $error) {
            DocumentStorage::rollbackWritesSince($storageCheckpoint);
            throw new ConflictHttpException($error->getMessage());
        } catch (\Throwable $error) {
            DocumentStorage::rollbackWritesSince($storageCheckpoint);
            throw $error;
        }
        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
            DocumentStorage::discardWritesSince($storageCheckpoint);
        } else {
            DocumentStorage::rollbackWritesSince($storageCheckpoint);
        }
        Yii::$app->response->statusCode = $result['statusCode'];
        Yii::$app->response->headers->set('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
        if ($result['location'] !== null) {
            Yii::$app->response->headers->set('Location', $result['location']);
        }
        return $result['body'];
    }

    protected function requiresIdempotency(): bool
    {
        return true;
    }

    /** @return array{errors: array<string, list<string>>}|null */
    protected function bodyValidationErrors(Model $input): ?array
    {
        $request = Yii::$app->request;
        $contentType = strtolower(trim(explode(';', $request->contentType ?? '', 2)[0]));
        if ($contentType !== 'application/json') {
            throw new UnsupportedMediaTypeHttpException('Content-Type must be application/json.');
        }

        $rawBody = $request->rawBody;
        if (trim($rawBody) === '') {
            throw new BadRequestHttpException('Request body must not be empty.');
        }

        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('Malformed JSON request body.');
        }

        if (!is_array($body) || !str_starts_with(ltrim($rawBody), '{')) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $input->load($body, '');
        if ($input->validate()) {
            return null;
        }

        Yii::$app->response->statusCode = 422;
        return ['errors' => $input->getErrors()];
    }
}
