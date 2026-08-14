<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Document\TestActDocumentService;
use App\Application\Document\TestActConfigurationError;
use App\Application\Document\TestActInput;
use App\Application\Request\Command\RequestLifecycleCommand;
use App\Application\Request\UseCase\ChangeRequestDepartment;
use App\Application\Request\ListRequestsInput;
use App\Application\Request\Command\AssignExpertCommand;
use App\Application\Request\Command\AssignExecutorCommand;
use App\Application\Request\Command\CancelRequestCommand;
use App\Application\Request\Command\DecideSecurityCommand;
use App\Application\Request\Command\DeleteReportCommand;
use App\Application\Request\Command\PublishOpinionCommand;
use App\Application\Request\Command\UploadReportCommand;
use App\Application\Request\UseCase\DecideSecurity;
use App\Application\Request\UseCase\PublishOpinion;
use App\Application\Request\UseCase\ReportLifecycle;
use App\Application\Request\UseCase\CreateRequest as CreateRequestUseCase;
use App\Application\Request\UseCase\SetRequestColor;
use App\Application\Request\UseCase\RequestLifecycle;
use App\Application\Request\UseCase\CancelRequest as CancelRequestUseCase;
use App\Application\Request\UseCase\AssignExecutor;
use App\Application\Request\UseCase\AssignExpert;
use App\Application\Request\UseCase\AddComment;
use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\AttachmentDenied;
use App\Domain\Request\ColorMarkDenied;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ExpertAssignmentDenied;
use App\Domain\Request\CommentDenied;
use App\Domain\Request\RejectDenied;
use App\Domain\Request\RequestCreationDenied;
use App\Domain\Request\RequestDepartmentChangeDenied;
use App\Domain\Request\RequestDepartmentMissing;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestAction;
use App\Domain\Request\ReportDenied;
use App\Domain\Request\ReportDeletionDenied;
use App\Domain\Request\OpinionDenied;
use App\Domain\Request\SecurityDecisionDenied;
use App\Domain\Request\StartDenied;
use App\Domain\Request\SuspendResumeDenied;
use App\Domain\Request\TransitionDenied;
use App\Domain\Request\WithdrawDenied;
use App\Infrastructure\Identity\CurrentUser;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Document\OfficeDocumentInspector;
use App\Infrastructure\Document\TestActDocumentGenerator;
use App\Infrastructure\Request\RequestQuery;
use App\Http\Request\AddCommentRequest;
use App\Http\Request\SetColorRequest;
use App\Http\Request\ChangeDepartmentRequest;
use App\Http\Request\LockVersionRequest;
use App\Http\Request\ReasonedLockVersionRequest;
use App\Http\Request\CancelRequest;
use App\Http\Request\AssignExecutorRequest;
use App\Http\Request\AssignExpertRequest;
use App\Http\Request\SecurityDecisionRequest;
use App\Http\Request\PublishOpinionRequest;
use App\Http\Request\CreateRequest;
use Yii;
use yii\web\Response;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnprocessableEntityHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\UploadedFile;

final class RequestController extends ApiController
{
    protected function requiresIdempotency(string $actionId): bool
    {
        // Generating a draft is a read-only binary response. The JSON idempotency store cannot
        // persist a DOCX response and no domain state needs duplicate-request protection here.
        return $actionId !== 'generate-test-act';
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        // yii\rest\Controller отключает CSRF по умолчанию (рассчитан на
        // token-based auth без cookie) — вне dev включаем явно, иначе
        // LDAP-сессия (реальные cookie) останется без защиты от CSRF.
        $this->enableCsrfValidation = true;
        if (!parent::beforeAction($action)) {
            return false;
        }

        return true;
    }

    public function bindActionParams($action, $params): array
    {
        $arguments = parent::bindActionParams($action, $params);
        $mutations = [
            'add-comment', 'upload-document', 'upload-report', 'delete-report', 'change-department',
            'set-color', 'assign-executor', 'claim-expert', 'reassign-expert', 'publish-opinion',
            'security-decision', 'start', 'suspend', 'resume', 'reject', 'withdraw',
        ];
        $requestId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (in_array($action->id, $mutations, true) && $requestId !== false && $this->query()->isArchived($requestId)) {
            throw new ConflictHttpException('Архивная заявка доступна только для просмотра.');
        }
        return $arguments;
    }

    /** @return array<string, mixed> */
    public function actionIndex(): array
    {
        $input = new ListRequestsInput();
        $input->load(Yii::$app->request->queryParams, '');
        if (!$input->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $input->getErrors()];
        }

        return $this->query()->findPage(
            $this->currentUserId(),
            (int) $input->page,
            (int) $input->pageSize,
            (string) $input->tab,
            $input->status === null || $input->status === '' ? null : (string) $input->status,
            trim((string) $input->query),
            (string) $input->sort,
            $input->attention === null || $input->attention === '' ? null : (string) $input->attention,
        );
    }

    /** @return array{categories: list<array{id: string, title: string, description: string, count: int}>} */
    public function actionDashboard(): array
    {
        return $this->query()->attentionDashboard($this->currentUserId());
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function actionEvents(): array
    {
        return ['items' => $this->query()->recentEvents($this->currentUserId())];
    }

    /** @return array{item: array<string, mixed>, history: list<array<string, mixed>>, comments: list<array<string, mixed>>, commentsPage: array{hasMore: bool, nextBeforeId: int|null}, documents: list<array<string, mixed>>} */
    public function actionView(int $id): array
    {
        $actorId = $this->currentUserId();
        try {
            return $this->query()->findDetails($id, $actorId);
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionAddComment(int $id): array
    {
        $input = new AddCommentRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        try {
            $comment = Yii::$container->get(AddComment::class)->execute(
                $input->toCommand($id, $this->currentUserId()),
            );
            Yii::$app->response->statusCode = 201;
            return $comment->toArray();
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
            return $this->query()->findCommentsPage(
                $id,
                $this->currentUserId(),
                $beforeId === false ? null : $beforeId,
            );
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionUploadDocument(int $id): array
    {
        $actorId = $this->currentUserId();
        $file = UploadedFile::getInstanceByName('file');
        if ($file === null || $file->error !== UPLOAD_ERR_OK) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => ['file' => ['Выберите файл размером не более 200 МБ.']]];
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

    /** @return array<string, mixed> */
    public function actionUploadReport(int $id): array
    {
        $actorId = $this->currentUserId();
        $file = UploadedFile::getInstanceByName('file');
        if ($file === null || $file->error !== UPLOAD_ERR_OK) {
            $this->recordRejectedReportSafely($id, $actorId, 'DOC-002A');
            Yii::$app->response->statusCode = 422;
            return ['errors' => ['file' => ['Выберите PDF-файл размером не более 200 МБ.']]];
        }
        $size = filesize($file->tempName);
        $mimeType = mime_content_type($file->tempName);
        if ($size === false || $mimeType === false) {
            throw new ServerErrorHttpException('Не удалось проверить загруженный отчёт.');
        }

        try {
            $command = new UploadReportCommand($id, $actorId, $file->name, $mimeType, $size, $file->tempName);
            $report = Yii::$container->get(ReportLifecycle::class)->upload($command)->toArray();
            Yii::$app->response->statusCode = 201;
            return $report;
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (ReportDenied $error) {
            $this->recordRejectedReportSafely($id, $actorId, $error->ruleId);
            if ($error->ruleId === 'DOC-002A') {
                Yii::$app->response->statusCode = 422;
                return ['errors' => ['file' => ['Отчёт должен быть PDF-файлом размером не более 200 МБ.']]];
            }
            throw new ForbiddenHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionDeleteReport(int $id): array
    {
        $input = new ReasonedLockVersionRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $actorId = $this->currentUserId();
        try {
            $command = new DeleteReportCommand($id, (int) $input->lockVersion, $actorId, (string) $input->reason);
            return Yii::$container->get(ReportLifecycle::class)->delete($command)->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (ReportDeletionDenied $error) {
            $this->recordRejectedReportDeletionSafely($id, $actorId, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedReportDeletionSafely($id, $actorId, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionPrepareTestAct(int $id): array
    {
        try {
            return $this->testActDocuments()->prepare($id, $this->currentUserId());
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (ReportDenied $error) {
            throw new ForbiddenHttpException($error->getMessage());
        } catch (TestActConfigurationError $error) {
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return Response|array<string, mixed> */
    public function actionGenerateTestAct(int $id): Response|array
    {
        $input = new TestActInput();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        try {
            $document = $this->testActDocuments()->generate($id, $this->currentUserId(), $input);
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (ReportDenied $error) {
            throw new ForbiddenHttpException($error->getMessage());
        } catch (TestActConfigurationError $error) {
            throw new ConflictHttpException($error->getMessage());
        }

        return Yii::$app->response->sendContentAsFile($document->content, $document->fileName, [
            'mimeType' => $document->mimeType,
            'inline' => false,
        ]);
    }

    public function actionDownloadDocument(int $id): Response
    {
        $actorId = $this->currentUserId();
        try {
            $version = $this->documents()->findVersionForDownload($id, $actorId);
        } catch (RequestNotFound $error) {
            $this->recordRejectedDownloadSafely($id, $actorId, 'not_found_or_inactive');
            throw new NotFoundHttpException($error->getMessage());
        }
        $path = $this->storage()->path((string) $version['storageKey']);
        if (!is_file($path)) {
            $this->recordRejectedDownloadSafely($id, $actorId, 'storage_unavailable');
            throw new NotFoundHttpException('Document version not found');
        }
        $this->documents()->recordDownload($id, (int) $version['requestId'], $actorId);
        return Yii::$app->response->sendFile($path, (string) $version['originalName'], [
            'mimeType' => (string) $version['mimeType'],
            'inline' => false,
        ]);
    }

    // ACL-003..006: ссылка из уведомления работает без входа в портал (ТЗ
    // 4.6/4.9/4.10) — сюда сознательно не подключается CurrentUser.
    public function actionDownloadDocumentLink(string $token): Response
    {
        $version = $this->documents()->findVersionByToken($token);
        if ($version === false) {
            throw new NotFoundHttpException('Document link not found');
        }
        $path = $this->storage()->path((string) $version['storageKey']);
        if (!is_file($path)) {
            throw new NotFoundHttpException('Document version not found');
        }
        return Yii::$app->response->sendFile($path, (string) $version['originalName'], [
            'mimeType' => (string) $version['mimeType'],
            'inline' => false,
        ]);
    }
    /** @return array{items: list<array{id: int, displayName: string}>} */
    public function actionExecutors(): array
    {
        $this->currentUserId();
        return ['items' => $this->query()->findActiveExecutors()];
    }

    /** @return array{items: list<array{id: int, displayName: string}>} */
    public function actionExperts(): array
    {
        $this->currentUserId();
        return ['items' => $this->query()->findActiveExperts()];
    }

    /** @return array<string, mixed> */
    public function actionCreate(): array
    {
        $input = new CreateRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $command = $input->toCommand($this->currentUserId());
        try {
            $request = Yii::$container->get(CreateRequestUseCase::class)->execute($command)->toArray();
        } catch (RequestCreationDenied $error) {
            $this->recordRejectedCreateSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (RequestDepartmentMissing $error) {
            throw new UnprocessableEntityHttpException($error->getMessage());
        }
        Yii::$app->response->statusCode = 201;
        Yii::$app->response->headers->set('Location', '/api/v1/requests/' . $request['id']);
        return $request;
    }
    /** @return array<string, mixed> */
    public function actionChangeDepartment(int $id): array
    {
        $input = new ChangeDepartmentRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }
        try {
            $result = Yii::$container->get(ChangeRequestDepartment::class)->execute(
                $input->toCommand($id, $this->currentUserId()),
            );
            return $result->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (RequestDepartmentChangeDenied $error) {
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionSetColor(int $id): array
    {
        $input = new SetColorRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }
        $actorId = $this->currentUserId();
        try {
            $result = Yii::$container->get(SetRequestColor::class)->execute($input->toCommand($id, $actorId));
            return $result->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (ColorMarkDenied $error) {
            $this->recordRejectedColorSafely($id, $actorId, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedColorSafely($id, $actorId, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionAssignExecutor(int $id): array
    {
        $input = new AssignExecutorRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $actorId = $this->currentUserId();
        $command = $input->toCommand($id, $actorId);

        try {
            return Yii::$container->get(AssignExecutor::class)->execute($command)->toArray();
        } catch (AssignmentTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (AssignmentDenied $error) {
            $this->recordRejectedAssignmentSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedAssignmentSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionClaimExpert(int $id): array
    {
        $input = new LockVersionRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $actorId = $this->currentUserId();
        $command = AssignExpertCommand::claim($id, (int) $input->lockVersion, $actorId);
        return $this->executeExpertAssignment($command);
    }

    /** @return array<string, mixed> */
    public function actionReassignExpert(int $id): array
    {
        $input = new AssignExpertRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $actorId = $this->currentUserId();
        $command = $input->toCommand($id, $actorId);
        return $this->executeExpertAssignment($command);
    }

    /** @return array<string, mixed> */
    private function executeExpertAssignment(AssignExpertCommand $command): array
    {
        try {
            return Yii::$container->get(AssignExpert::class)->execute($command)->toArray();
        } catch (AssignmentTargetNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (ExpertAssignmentDenied $error) {
            $this->recordRejectedExpertAssignmentSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedExpertAssignmentSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionPublishOpinion(int $id): array
    {
        $input = new PublishOpinionRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $command = $input->toCommand($id, $this->currentUserId());
        try {
            return Yii::$container->get(PublishOpinion::class)->execute($command)->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (OpinionDenied $error) {
            $this->recordRejectedOpinionSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedOpinionSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionSecurityDecision(int $id): array
    {
        $input = new SecurityDecisionRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $actorId = $this->currentUserId();
        $command = $input->toCommand($id, $actorId);
        try {
            return Yii::$container->get(DecideSecurity::class)->execute($command)->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (SecurityDecisionDenied $error) {
            $this->recordRejectedSecurityDecisionSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedSecurityDecisionSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionStart(int $id): array
    {
        $input = new LockVersionRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $actorId = $this->currentUserId();
        $command = $input->toLifecycleCommand($id, $actorId, RequestAction::Start);
        try {
            return Yii::$container->get(RequestLifecycle::class)->execute($command)->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (StartDenied $error) {
            $this->recordRejectedStartSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (TransitionDenied | ConcurrentRequestModification $error) {
            $this->recordRejectedStartSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionSuspend(int $id): array
    {
        $input = new ReasonedLockVersionRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        return $this->executeSuspendResume(
            $input->toLifecycleCommand($id, $this->currentUserId(), RequestAction::Suspend),
        );
    }

    /** @return array<string, mixed> */
    public function actionResume(int $id): array
    {
        $input = new LockVersionRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        return $this->executeSuspendResume(
            $input->toLifecycleCommand($id, $this->currentUserId(), RequestAction::Resume),
        );
    }

    /** @return array<string, mixed> */
    private function executeSuspendResume(RequestLifecycleCommand $command): array
    {
        try {
            return Yii::$container->get(RequestLifecycle::class)->execute($command)->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (SuspendResumeDenied $error) {
            $this->recordRejectedSuspendResumeSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (TransitionDenied $error) {
            $this->recordRejectedSuspendResumeSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionReject(int $id): array
    {
        $input = new CancelRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $command = $input->toCommand($id, $this->currentUserId(), RequestAction::Reject);
        try {
            return Yii::$container->get(CancelRequestUseCase::class)->execute($command)->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (RejectDenied $error) {
            $this->recordRejectedCancellationSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedCancellationSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function actionWithdraw(int $id): array
    {
        $input = new CancelRequest();
        if (($errors = $this->bodyValidationErrors($input)) !== null) {
            return $errors;
        }

        $command = $input->toCommand($id, $this->currentUserId(), RequestAction::Withdraw);
        try {
            return Yii::$container->get(CancelRequestUseCase::class)->execute($command)->toArray();
        } catch (RequestNotFound $error) {
            throw new NotFoundHttpException($error->getMessage());
        } catch (WithdrawDenied $error) {
            $this->recordRejectedCancellationSafely($command, $error->ruleId);
            throw new ForbiddenHttpException($error->getMessage());
        } catch (ConcurrentRequestModification $error) {
            $this->recordRejectedCancellationSafely($command, $error->ruleId);
            throw new ConflictHttpException($error->getMessage());
        }
    }

    private function recordRejectedCreateSafely(\App\Application\Request\Command\CreateRequestCommand $command, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(CreateRequestUseCase::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённого создания заявки.',
            ['actorId' => $command->initiatorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedStartSafely(RequestLifecycleCommand $command, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(RequestLifecycle::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённого запуска заявки.',
            ['requestId' => $command->requestId, 'actorId' => $command->actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedSuspendResumeSafely(RequestLifecycleCommand $command, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(RequestLifecycle::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённой приостановки/возобновления заявки.',
            ['requestId' => $command->requestId, 'actorId' => $command->actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedCancellationSafely(CancelRequestCommand $command, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(CancelRequestUseCase::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённой отмены заявки.',
            ['requestId' => $command->requestId, 'actorId' => $command->actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedColorSafely(int $requestId, int $actorId, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(SetRequestColor::class)->recordRejected($requestId, $actorId, $ruleId),
            'Не удалось записать аудит отклонённой цветовой метки.',
            ['requestId' => $requestId, 'actorId' => $actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedAssignmentSafely(AssignExecutorCommand $command, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(AssignExecutor::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённого назначения.',
            [
                'requestId' => $command->requestId,
                'executorId' => $command->executorId,
                'actorId' => $command->actorId,
                'ruleId' => $ruleId,
            ],
            __METHOD__,
        );
    }

    private function recordRejectedExpertAssignmentSafely(
        AssignExpertCommand $command,
        string $ruleId,
    ): void {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(AssignExpert::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённого назначения эксперта.',
            [
                'requestId' => $command->requestId,
                'expertId' => $command->expertId,
                'actorId' => $command->actorId,
                'ruleId' => $ruleId,
            ],
            __METHOD__,
        );
    }

    private function recordRejectedDownloadSafely(int $versionId, int $actorId, string $reason): void
    {
        $this->recordRejectedSafely(
            fn () => $this->documents()->recordRejectedDownload($versionId, $actorId, $reason),
            'Не удалось записать аудит отклонённого скачивания.',
            ['versionId' => $versionId, 'actorId' => $actorId, 'reason' => $reason],
            __METHOD__,
        );
    }

    private function recordRejectedReportSafely(int $requestId, int $actorId, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(ReportLifecycle::class)
                ->recordRejectedUpload($requestId, $actorId, $ruleId),
            'Не удалось записать аудит отклонённой загрузки отчёта.',
            ['requestId' => $requestId, 'actorId' => $actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedReportDeletionSafely(int $requestId, int $actorId, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(ReportLifecycle::class)
                ->recordRejectedDeletion($requestId, $actorId, $ruleId),
            'Не удалось записать аудит отклонённого удаления отчёта.',
            ['requestId' => $requestId, 'actorId' => $actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedOpinionSafely(PublishOpinionCommand $command, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(PublishOpinion::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённой публикации заключения.',
            ['requestId' => $command->requestId, 'actorId' => $command->actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    private function recordRejectedSecurityDecisionSafely(DecideSecurityCommand $command, string $ruleId): void
    {
        $this->recordRejectedSafely(
            fn () => Yii::$container->get(DecideSecurity::class)->recordRejected($command, $ruleId),
            'Не удалось записать аудит отклонённого решения СБ.',
            ['requestId' => $command->requestId, 'actorId' => $command->actorId, 'ruleId' => $ruleId],
            __METHOD__,
        );
    }

    /**
     * @param callable(): void $record
     * @param array<string, int|string> $context
     */
    private function recordRejectedSafely(
        callable $record,
        string $message,
        array $context,
        string $category,
    ): void {
        try {
            $record();
        } catch (\Throwable $auditError) {
            Yii::error(
                ['message' => $message, ...$context, 'exception' => $auditError],
                $category,
            );
        }
    }

    private function currentUserId(): int
    {
        return (new CurrentUser(Yii::$app->db))->id(Yii::$app->request);
    }

    private function query(): RequestQuery
    {
        return new RequestQuery(Yii::$app->db);
    }

    private function documents(): DocumentRepository
    {
        return new DocumentRepository(Yii::$app->db, $this->storage());
    }

    private function testActDocuments(): TestActDocumentService
    {
        return new TestActDocumentService(Yii::$app->db, new TestActDocumentGenerator());
    }

    private function storage(): DocumentStorage
    {
        return new DocumentStorage(getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents');
    }
}
