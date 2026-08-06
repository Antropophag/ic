<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Development\DevelopmentRequestSeeder;
use App\Infrastructure\Development\ReviewFeedbackRepository;
use App\Infrastructure\Deployment\DatabasePurpose;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Identity\CurrentUser;
use Yii;
use yii\rest\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\UnprocessableEntityHttpException;

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
        $this->assertDevelopmentDatabase();

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
        $this->assertDevelopmentDatabase();

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

        return (new DevelopmentRequestSeeder(
            Yii::$app->db,
            new DocumentStorage(getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents'),
        ))->seed();
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionReviewFeedback(): array
    {
        $this->assertDevelopmentDatabase();
        (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);

        return ['items' => $this->feedbackRepository()->latest()];
    }

    /** @return array<string, mixed> */
    public function actionCreateReviewFeedback(): array
    {
        $this->assertDevelopmentDatabase();
        $authorId = (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
        $rawBody = Yii::$app->request->getBodyParam('body');
        $rawChecklist = Yii::$app->request->getBodyParam('checklist', []);
        if (!is_string($rawBody)) {
            throw new UnprocessableEntityHttpException('Текст замечания должен быть строкой.');
        }
        $body = trim($rawBody);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new UnprocessableEntityHttpException('Введите текст замечания длиной до 5000 символов.');
        }
        if (!is_array($rawChecklist) || count($rawChecklist) > 50) {
            throw new UnprocessableEntityHttpException('Некорректный чек-лист замечания.');
        }
        $checklist = [];
        foreach ($rawChecklist as $item) {
            if (!is_string($item) || mb_strlen($item) > 200) {
                throw new UnprocessableEntityHttpException('Некорректный пункт чек-листа.');
            }
            $item = trim($item);
            if ($item !== '') {
                $checklist[] = $item;
            }
        }

        Yii::$app->response->statusCode = 201;
        return $this->feedbackRepository()->create($authorId, $body, array_values(array_unique($checklist)));
    }

    private function assertDevelopmentDatabase(): void
    {
        $database = Yii::$app->db->createCommand('SELECT DATABASE()')->queryScalar();
        if (!is_string($database) || !DatabasePurpose::isDevelopment($database)) {
            throw new ForbiddenHttpException(
                'Инструменты разработки доступны только для БД с именем, оканчивающимся на _dev.',
            );
        }
    }

    private function feedbackRepository(): ReviewFeedbackRepository
    {
        return new ReviewFeedbackRepository(Yii::$app->db);
    }
}
