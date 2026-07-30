<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Development\DemoRequestSeeder;
use App\Infrastructure\Document\DocumentStorage;
use Yii;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;

final class DevController extends Controller
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);
        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        // Проверяем окружение до parent::beforeAction(): иначе вне dev
        // CSRF-фильтр перехватит POST и раскроет маршрут ответом 400 вместо 404.
        if (YII_ENV !== 'dev') {
            throw new NotFoundHttpException();
        }
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /** @return array{requests: int, comments: int, documents: int} */
    public function actionSeedRequests(): array
    {
        return (new DemoRequestSeeder(
            Yii::$app->db,
            new DocumentStorage(getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents'),
        ))->seed();
    }
}
