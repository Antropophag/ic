<?php

declare(strict_types=1);

namespace App\Http\Controller;

use JsonException;
use Yii;
use yii\base\Model;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;
use yii\web\UnsupportedMediaTypeHttpException;

abstract class ApiController extends Controller
{
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
