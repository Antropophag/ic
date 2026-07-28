<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Request\CreateRequestInput;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Request\RequestRepository;
use Yii;
use yii\rest\Controller;
use yii\web\Response;

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

    public function actionIndex(): array
    {
        (new CurrentUser())->id(Yii::$app->request);
        return ['items' => $this->repository()->findLatest()];
    }

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

    private function repository(): RequestRepository
    {
        return new RequestRepository(Yii::$app->db);
    }
}
