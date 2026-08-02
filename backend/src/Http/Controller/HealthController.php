<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Identity\RoleManagementDenied;
use App\Domain\Identity\RoleManagementPolicy;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Identity\UserAdministrationRepository;
use Yii;
use yii\rest\Controller;
use yii\web\ForbiddenHttpException;
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
        $actorId = (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
        $repository = new UserAdministrationRepository(Yii::$app->db);
        try {
            (new RoleManagementPolicy())->assertCanManage(
                $this->isActiveUser($actorId),
                $repository->rolesFor($actorId),
            );
        } catch (RoleManagementDenied $error) {
            throw new ForbiddenHttpException($error->getMessage());
        }

        Yii::error('Logging probe', 'health.logging');
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

    private function isActiveUser(int $userId): bool
    {
        return Yii::$app->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $userId],
        )->queryScalar() !== false;
    }
}
