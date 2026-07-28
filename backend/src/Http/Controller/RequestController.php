<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Request\CreateRequestInput;
use App\Application\Request\AssignExecutorInput;
use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Request\RequestRepository;
use Yii;
use yii\rest\Controller;
use yii\web\Response;
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
        (new CurrentUser())->id(Yii::$app->request);
        return ['items' => $this->repository()->findLatest()];
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

        try {
            return $this->repository()->assignExecutor(
                $id,
                (int) $input->executorId,
                (new CurrentUser())->id(Yii::$app->request),
            );
        } catch (AssignmentTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (AssignmentDenied $error) {
            $this->repository()->recordRejectedAssignment(
                $id,
                (int) $input->executorId,
                (new CurrentUser())->id(Yii::$app->request),
                $error->ruleId,
            );
            throw new ForbiddenHttpException($error->getMessage());
        }
    }

    private function repository(): RequestRepository
    {
        return new RequestRepository(Yii::$app->db);
    }
}
