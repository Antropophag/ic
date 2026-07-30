<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\AssignRoleInput;
use App\Application\Identity\CreateUserInput;
use App\Domain\Identity\DuplicateAdLogin;
use App\Domain\Identity\RoleManagementDenied;
use App\Domain\Identity\RoleManagementPolicy;
use App\Domain\Identity\UserAdministrationTargetNotFound;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Identity\UserAdministrationRepository;
use Yii;
use yii\rest\Controller;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

final class AdminController extends Controller
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = YII_ENV !== 'dev';

        return parent::beforeAction($action);
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionUsers(): array
    {
        $this->authorize();
        return ['items' => $this->repository()->listUsers()];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionRoles(): array
    {
        $this->authorize();
        return ['items' => $this->repository()->listRoles()];
    }

    /** @return array<string, mixed> */
    public function actionCreateUser(): array
    {
        $actorId = $this->authorize();

        $input = new CreateUserInput();
        $input->load(Yii::$app->request->bodyParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        $displayName = trim((string) $input->displayName);
        if ($displayName === '') {
            $displayName = (string) $input->adLogin;
        }

        try {
            $user = $this->repository()->createPlaceholder(
                (string) $input->adLogin,
                $displayName,
                $actorId,
            );
        } catch (DuplicateAdLogin $error) {
            throw new ConflictHttpException($error->getMessage());
        }

        Yii::$app->response->statusCode = 201;
        return $user;
    }

    /** @return array<string, mixed> */
    public function actionAssignRole(int $userId): array
    {
        $actorId = $this->authorize();

        $input = new AssignRoleInput();
        $input->load(Yii::$app->request->bodyParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        try {
            $roles = $this->repository()->assignRole($userId, (int) $input->roleId, $actorId);
        } catch (UserAdministrationTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }

        return ['items' => $roles];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionRevokeRole(int $userId, int $roleId): array
    {
        $actorId = $this->authorize();

        try {
            $roles = $this->repository()->revokeRole($userId, $roleId, $actorId);
        } catch (UserAdministrationTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }

        return ['items' => $roles];
    }

    private function authorize(): int
    {
        $actorId = $this->currentUserId();
        try {
            (new RoleManagementPolicy())->assertCanManage(
                $this->isActiveUser($actorId),
                $this->repository()->rolesFor($actorId),
            );
        } catch (RoleManagementDenied $error) {
            throw new ForbiddenHttpException($error->getMessage());
        }

        return $actorId;
    }

    private function isActiveUser(int $userId): bool
    {
        return Yii::$app->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $userId],
        )->queryScalar() !== false;
    }

    private function currentUserId(): int
    {
        return (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
    }

    private function repository(): UserAdministrationRepository
    {
        return new UserAdministrationRepository(Yii::$app->db);
    }
}
