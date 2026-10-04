<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Ai\AiFeatureUnavailable;
use App\Application\Ai\AiRequestLifecycle;
use App\Application\Ai\AnalyzeTechnicalSpecification;
use App\Application\Ai\CreateTestSpecificationDraft;
use App\Application\Ai\TechnicalSpecificationUnavailable;
use App\Domain\Request\RequestNotFound;
use Yii;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnprocessableEntityHttpException;

final class AiRequestController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = true;
        return parent::beforeAction($action);
    }

    /** @return array<string, mixed> */
    public function actionAnalyze(int $id): array
    {
        $rawVersionId = Yii::$app->request->bodyParams['documentVersionId'] ?? null;
        $versionId = $rawVersionId === null ? null : filter_var($rawVersionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($rawVersionId !== null && $versionId === false) {
            throw new UnprocessableEntityHttpException('Некорректная версия документа.');
        }
        try {
            return Yii::$container->get(AnalyzeTechnicalSpecification::class)->execute(
                $id,
                $this->authenticatedUserId(),
                $versionId === false ? null : $versionId,
            );
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (TechnicalSpecificationUnavailable $error) {
            throw new UnprocessableEntityHttpException($error->getMessage());
        } catch (AiFeatureUnavailable $error) {
            throw new HttpException(503, $error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionDraft(int $id): array
    {
        $rawVersionId = Yii::$app->request->bodyParams['documentVersionId'] ?? null;
        $versionId = $rawVersionId === null ? null : filter_var($rawVersionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($rawVersionId !== null && $versionId === false) {
            throw new UnprocessableEntityHttpException('Некорректная версия документа.');
        }
        try {
            return Yii::$container->get(CreateTestSpecificationDraft::class)->execute(
                $id,
                $this->authenticatedUserId(),
                $versionId === false ? null : $versionId,
            );
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (TechnicalSpecificationUnavailable $error) {
            throw new UnprocessableEntityHttpException($error->getMessage());
        } catch (AiFeatureUnavailable $error) {
            throw new HttpException(503, $error->getMessage());
        }
    }

    protected function executeIdempotent(
        int $actorId,
        string $method,
        string $route,
        string $key,
        string $requestHash,
        callable $operation,
        callable $statusCode,
        callable $location,
    ): array {
        return Yii::$container->get(AiRequestLifecycle::class)->execute(
            $actorId,
            $method,
            $route,
            $key,
            $requestHash,
            $operation,
            $statusCode,
            $location,
        );
    }
}
