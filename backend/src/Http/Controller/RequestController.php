<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Request\CreateRequestInput;
use App\Application\Request\AddCommentInput;
use App\Application\Request\AssignExecutorInput;
use App\Application\Request\StartRequestInput;
use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\AttachmentDenied;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\CommentDenied;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\StartDenied;
use App\Domain\Request\TransitionDenied;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Document\OfficeDocumentInspector;
use App\Infrastructure\Request\RequestRepository;
use Yii;
use yii\rest\Controller;
use yii\web\Response;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\UploadedFile;

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

    /** @return array{item: array<string, mixed>, history: list<array<string, mixed>>, comments: list<array<string, mixed>>, commentsPage: array{hasMore: bool, nextBeforeId: int|null}, documents: list<array<string, mixed>>} */
    public function actionView(int $id): array
    {
        $actorId = (new CurrentUser())->id(Yii::$app->request);
        try {
            return $this->repository()->findDetails($id, $actorId);
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionAddComment(int $id): array
    {
        $input = new AddCommentInput();
        $input->load(Yii::$app->request->bodyParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        try {
            $comment = $this->repository()->addComment(
                $id,
                (new CurrentUser())->id(Yii::$app->request),
                (string) $input->body,
            );
            Yii::$app->response->statusCode = 201;
            return $comment;
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (CommentDenied $error) {
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array{items: list<array<string, mixed>>, hasMore: bool, nextBeforeId: int|null} */
    public function actionComments(int $id): array
    {
        $rawBeforeId = Yii::$app->request->get('beforeId');
        $beforeId = $rawBeforeId === null ? null : filter_var($rawBeforeId, FILTER_VALIDATE_INT);
        if ($rawBeforeId !== null && ($beforeId === false || $beforeId < 1)) {
            Yii::$app->response->statusCode = 422;
            return ['items' => [], 'hasMore' => false, 'nextBeforeId' => null];
        }
        try {
            return $this->repository()->findCommentsPage(
                $id,
                (new CurrentUser())->id(Yii::$app->request),
                $beforeId === false ? null : $beforeId,
            );
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionUploadDocument(int $id): array
    {
        $actorId = (new CurrentUser())->id(Yii::$app->request);
        $file = UploadedFile::getInstanceByName('file');
        if ($file === null || $file->error !== UPLOAD_ERR_OK) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => ['file' => ['Выберите файл размером не более 10 МБ.']]];
        }
        $size = filesize($file->tempName);
        $mimeType = mime_content_type($file->tempName);
        if ($size === false || $mimeType === false) {
            throw new ServerErrorHttpException('Не удалось проверить загруженный файл.');
        }

        try {
            $mimeType = (new OfficeDocumentInspector())->normalizeMimeType($file->name, $mimeType, $file->tempName);
            $document = $this->documents()->upload(
                $id,
                $actorId,
                $file->name,
                $mimeType,
                $size,
                $file->tempName,
            );
            Yii::$app->response->statusCode = 201;
            return $document;
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (AttachmentDenied $error) {
            if ($error->ruleId === 'COM-007') {
                Yii::$app->response->statusCode = 422;
                return ['errors' => ['file' => ['Тип, расширение или размер файла не разрешены.']]];
            }
            throw new ConflictHttpException($error->getMessage());
        }
    }

    public function actionDownloadDocument(int $id): Response
    {
        $actorId = (new CurrentUser())->id(Yii::$app->request);
        try {
            $version = $this->documents()->findVersionForDownload($id, $actorId);
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }
        $path = $this->storage()->path((string) $version['storageKey']);
        if (!is_file($path)) {
            throw new NotFoundHttpException('Document version not found');
        }
        $this->documents()->recordDownload($id, (int) $version['requestId'], $actorId);
        return Yii::$app->response->sendFile($path, (string) $version['originalName'], [
            'mimeType' => (string) $version['mimeType'],
            'inline' => false,
        ]);
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

    private function documents(): DocumentRepository
    {
        return new DocumentRepository(Yii::$app->db, $this->storage());
    }

    private function storage(): DocumentStorage
    {
        return new DocumentStorage(getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents');
    }
}
