<?php

declare(strict_types=1);

namespace App\Http\Controller;

use Yii;
use yii\rest\Controller;
use yii\web\ServerErrorHttpException;

final class HealthController extends Controller
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    /** @return array{status: 'ok'} */
    public function actionLive(): array
    {
        return ['status' => 'ok'];
    }

    /** @return array{status: 'ready', database: 'ok'} */
    public function actionReady(): array
    {
        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        } catch (\Throwable) {
            throw new ServerErrorHttpException('Application is not ready');
        }

        return ['status' => 'ready', 'database' => 'ok'];
    }
}
