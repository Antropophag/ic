<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Development\DevelopmentRequestSeeder;
use App\Infrastructure\Deployment\DatabasePurpose;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Identity\CurrentUser;
use Yii;
use yii\rest\Controller;
use yii\web\ForbiddenHttpException;

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
        $this->enableCsrfValidation = true;
        return parent::beforeAction($action);
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionUsers(): array
    {
        $adLogins = array_column(\App\Console\DevController::CORE_USERS, 'ad_login');
        $params = [];
        $placeholders = [];
        foreach ($adLogins as $i => $adLogin) {
            $placeholders[] = ":login{$i}";
            $params[":login{$i}"] = $adLogin;
        }
        $rows = Yii::$app->db->createCommand(
            'SELECT u.id, u.display_name AS displayName, u.position, '
            . 'GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ",") AS roleCodes '
            . 'FROM {{%users}} u '
            . 'LEFT JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'LEFT JOIN {{%roles}} r ON r.id = ur.role_id '
            . 'WHERE u.ad_login IN (' . implode(',', $placeholders) . ') AND u.is_active = 1 '
            . 'GROUP BY u.id, u.display_name, u.position ORDER BY u.id',
            $params,
        )->queryAll();

        return ['items' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'displayName' => (string) $row['displayName'],
            'position' => (string) $row['position'],
            'roles' => $row['roleCodes'] === null ? [] : explode(',', (string) $row['roleCodes']),
        ], $rows)];
    }

    /** @return array{requests: int, comments: int, documents: int} */
    public function actionSeedRequests(): array
    {
        $actorId = (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
        $isAdministrator = Yii::$app->db->createCommand(
            'SELECT 1 FROM {{%user_roles}} ur '
            . 'INNER JOIN {{%roles}} r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :user_id AND r.code = :role_code',
            [':user_id' => $actorId, ':role_code' => 'administrator'],
        )->queryScalar();
        if ($isAdministrator === false) {
            throw new ForbiddenHttpException('Для сброса реестра требуются права администратора.');
        }

        $database = Yii::$app->db->createCommand('SELECT DATABASE()')->queryScalar();
        if (!is_string($database) || !DatabasePurpose::isDevelopment($database)) {
            throw new ForbiddenHttpException(
                'Development request seed is available only on a database ending with _dev.',
            );
        }

        return (new DevelopmentRequestSeeder(
            Yii::$app->db,
            new DocumentStorage(getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents'),
        ))->seed();
    }
}
