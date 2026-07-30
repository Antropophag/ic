<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Document\DocumentStorage;
use Yii;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;
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

    /** @return array{status: 'ok'} */
    public function actionLogging(): array
    {
        if (YII_ENV !== 'dev') {
            throw new NotFoundHttpException();
        }

        Yii::error('Logging smoke probe', 'health.logging');
        Yii::getLogger()->flush(true);

        return ['status' => 'ok'];
    }

    /** @return array{status: 'ready', database: 'ok', documentStorage: 'ok'} */
    public function actionReady(): array
    {
        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            $storagePath = getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents';
            (new DocumentStorage($storagePath))->assertWritable();
        } catch (\Throwable) {
            throw new ServerErrorHttpException('Application is not ready');
        }

        return ['status' => 'ready', 'database' => 'ok', 'documentStorage' => 'ok'];
    }
}
