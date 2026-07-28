<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Request\CreateRequestInput;
use App\Application\Request\AssignExecutorInput;
use App\Application\Request\StartRequestInput;
use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\StartDenied;
use App\Domain\Request\TransitionDenied;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Request\RequestRepository;
use Yii;
use yii\rest\Controller;
use yii\web\Response;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

final class RequestController extends Controller
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        // JSON API использует dev-заголовок только в локальном контуре. После
        // LDAP входа production остаётся защищён стандартным CSRF Yii.
        if (YII_ENV === 'dev') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionIndex(): array
    {
        $actorId = (new CurrentUser())->id(Yii::$app->request);
        return ['items' => $this->repository()->findLatest($actorId)];
    }

    /** @return array{items: list<array{id: int, displayName: string}>} */
    public function actionExecutors(): array
    {
        (new CurrentUser())->id(Yii::$app->request);
        return ['items' => $this->repository()->findActiveExecutors()];
    }

    /** @return array<string, mixed> */
    public function actionCreate(): array
    {
        $input = new CreateRequestInput();
        $input->load(Yii::$app->request->bodyParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        $request = $this->repository()->create(
            $input,
            (new CurrentUser())->id(Yii::$app->request),
        );
        Yii::$app->response->statusCode = 201;
        Yii::$app->response->headers->set('Location', '/api/v1/requests/' . $request['id']);
        return $request;
    }

    /** @return array<string, mixed> */
    public function actionAssignExecutor(int $id): array
    {
        $input = new AssignExecutorInput();
        $input->load(Yii::$app->request->bodyParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        $executorId = (int) $input->executorId;
        $actorId = (new CurrentUser())->id(Yii::$app->request);

        try {
            return $this->repository()->assignExecutor(
                $id,
                $executorId,
                (int) $input->lockVersion,
                $actorId,
            );
        } catch (AssignmentTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (AssignmentDenied $error) {
            $this->recordRejectedAssignmentSafely($id, $executorId, $actorId, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedAssignmentSafely($id, $executorId, $actorId, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionStart(int $id): array
    {
        $input = new StartRequestInput();
        $input->load(Yii::$app->request->bodyParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        $actorId = (new CurrentUser())->id(Yii::$app->request);
        try {
            return $this->repository()->startRequest($id, (int) $input->lockVersion, $actorId);
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (StartDenied $error) {
            $this->recordRejectedStartSafely($id, $actorId, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (TransitionDenied | ConcurrentRequestModification $error) {
            $this->recordRejectedStartSafely($id, $actorId, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    private function recordRejectedStartSafely(int $requestId, int $actorId, string $ruleId): void
    {
        try {
            $this->repository()->recordRejectedStart($requestId, $actorId, $ruleId);
        } catch (\Throwable $auditError) {
            Yii::error([
                'message' => 'Не удалось записать аудит отклонённого запуска заявки.',
                'requestId' => $requestId,
                'actorId' => $actorId,
                'ruleId' => $ruleId,
                'exception' => $auditError,
            ], __METHOD__);
        }
    }

    private function recordRejectedAssignmentSafely(
        int $requestId,
        int $executorId,
        int $actorId,
        string $ruleId,
    ): void {
        try {
            $this->repository()->recordRejectedAssignment($requestId, $executorId, $actorId, $ruleId);
        } catch (\Throwable $auditError) {
            Yii::error([
                'message' => 'Не удалось записать аудит отклонённого назначения.',
                'requestId' => $requestId,
                'executorId' => $executorId,
                'actorId' => $actorId,
                'ruleId' => $ruleId,
                'exception' => $auditError,
            ], __METHOD__);
        }
    }

    private function repository(): RequestRepository
    {
        return new RequestRepository(Yii::$app->db);
    }
}
