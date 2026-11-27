<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestRouteDenied;
use App\Domain\Request\SecurityDecisionDenied;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Document\OpinionPdfRenderer;
use App\Infrastructure\Request\RequestQuery;
use App\Infrastructure\Request\RequestRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;
use App\Http\Controller\RequestController;
use Yii;
use yii\web\Application;
use yii\web\Request;
use yii\web\HttpException;

final class RequestRoutesTest extends IntegrationTestCase
{
    private string $storageRoot;
    private string $pdf;
    /** @var list<int> */
    private array $externalActors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir() . '/ic-routes-' . bin2hex(random_bytes(8));
        mkdir($this->storageRoot, 0700);
        $this->pdf = $this->storageRoot . '/input.pdf';
        file_put_contents($this->pdf, '%PDF-1.4 route test');
    }

    protected function tearDown(): void
    {
        try {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storageRoot);
        } finally {
            Yii::$app?->errorHandler->unregister();
            Yii::$app = null;
            parent::tearDown();
            foreach ($this->externalActors as $actor) {
                $this->db()->createCommand()->delete('{{%user_roles}}', ['user_id' => $actor])->execute();
                $this->db()->createCommand()->delete('{{%users}}', ['id' => $actor])->execute();
            }
        }
    }

    /** @return array{int, int, int, int, int} */
    private function fixture(string $managerRole = 'ic_manager'): array
    {
        $initiator = $this->createUser('routes.initiator', 'Инициатор');
        $manager = $this->createUser('routes.manager', 'Руководитель');
        $executor = $this->createUser('routes.executor', 'Исполнитель');
        $expert = $this->createUser('routes.expert', 'Эксперт');
        $security = $this->createUser('routes.security', 'СБ');
        foreach ([$manager => $managerRole, $executor => 'ic_executor', $expert => 'expert', $security => 'security_officer'] as $id => $role) {
            $this->grantRole($id, $role);
        }
        $input = new CreateRequestInput();
        $input->load(['objects' => [['productName' => 'Образец', 'sampleQuantity' => '1 шт']], 'manufacturer' => 'Завод', 'supplier' => 'Поставщик', 'testMethod' => 'Программа'], '');
        self::assertTrue($input->validate());
        $request = (new RequestRepository($this->db()))->create($input, $initiator);
        self::assertNull($request['route']);
        return [(int) $request['id'], $manager, $executor, $expert, $security];
    }

    private function documents(): DocumentRepository
    {
        return new DocumentRepository($this->db(), new DocumentStorage($this->storageRoot));
    }

    /** @return array<string, mixed> */
    private function report(int $id, int $actor): array
    {
        return $this->documents()->uploadReport($id, $actor, 'report.pdf', 'application/pdf', (int) filesize($this->pdf), $this->pdf);
    }

    private function version(int $id): int
    {
        return (int) $this->scalar('SELECT lock_version FROM {{%requests}} WHERE id = :id', [':id' => $id]);
    }

    private function start(int $id, int $manager, int $executor): void
    {
        $repository = new RequestRepository($this->db());
        self::assertSame('registered', $this->scalar('SELECT status FROM {{%requests}} WHERE id = :id', [':id' => $id]));
        self::assertSame(1, (int) (new RequestQuery($this->db()))->findDetails($id, $manager)['item']['can_assign_executor']);
        $repository->assignExecutor($id, $executor, $this->version($id), $manager);
        self::assertSame('registered', $this->scalar('SELECT status FROM {{%requests}} WHERE id = :id', [':id' => $id]));
        $repository->startRequest($id, $this->version($id), $executor);
    }

    /** @param array<string, mixed> $body */
    private function controller(int $actor, array $body): RequestController
    {
        Yii::$app?->errorHandler->unregister();
        $application = new Application([
            'id' => 'route-http-test',
            'basePath' => dirname(__DIR__, 3),
            'params' => ['identityHeader' => 'X-Test-User-ID'],
            'components' => [
                'db' => $this->db(),
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'route-http-test'],
            ],
        ]);
        $application->request->headers->set('X-Test-User-ID', (string) $actor);
        $application->request->headers->set('Content-Type', 'application/json');
        $application->request->setRawBody(json_encode((object) $body, JSON_THROW_ON_ERROR));
        return new RequestController('request', $application);
    }

    private function assertHttpFailure(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('HTTP command must fail');
        } catch (HttpException $error) {
            self::assertSame($status, $error->statusCode);
        }
    }

    public function testClosureHttpBoundary(): void
    {
        [$id, $manager, $executor, $expert] = $this->fixture();
        foreach ([[], ['lockVersion' => 1, 'expertiseRequired' => false], ['lockVersion' => 1, 'securityRequired' => 'false']] as $body) {
            self::assertArrayHasKey('errors', $this->controller($manager, $body)->actionClose($id));
            self::assertSame(422, Yii::$app->response->statusCode);
        }
        $this->start($id, $manager, $executor);
        $body = ['lockVersion' => $this->version($id)];
        $this->assertHttpFailure(403, fn () => $this->controller($manager, $body)->actionClose($id));
        $this->report($id, $executor);
        $body = ['lockVersion' => $this->version($id)];
        $this->assertHttpFailure(403, fn () => $this->controller($expert, $body)->actionClose($id));
        $this->assertHttpFailure(404, fn () => $this->controller($manager, $body)->actionClose($id + 1000000));
        $this->assertHttpFailure(409, fn () => $this->controller($manager, ['lockVersion' => $body['lockVersion'] - 1])->actionClose($id));
        $result = $this->controller($manager, $body)->actionClose($id);
        self::assertSame('opinion_preparation', $result['status']);
        self::assertSame('protocol', $this->scalar('SELECT route FROM {{%requests}} WHERE id = :id', [':id' => $id]));
        $this->assertHttpFailure(409, fn () => $this->controller($manager, $body)->actionClose($id));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function closureChoices(): iterable
    {
        foreach (['ic_manager', 'laboratory_manager'] as $role) {
            yield "$role full" => [$role, true, true];
            yield "$role expertise" => [$role, true, false];
            yield "$role none" => [$role, false, false];
        }
    }

    #[DataProvider('closureChoices')]
    public function testManagerSelectsChecksAfterReport(string $role, bool $expertise, bool $security): void
    {
        [$id, $manager, $executor, $expert, $officer] = $this->fixture($role);
        $repository = new RequestRepository($this->db());
        $query = new RequestQuery($this->db());
        self::assertSame(1, (int) $query->findDetails($id, $manager)['item']['can_assign_executor']);
        $this->start($id, $manager, $executor);
        $report = $this->report($id, $executor);
        self::assertSame('in_progress', $query->findDetails($id, $manager)['item']['status']);
        self::assertSame(1, (int) $query->findDetails($id, $manager)['item']['can_close_request']);
        self::assertSame(0, (int) $query->findDetails($id, $executor)['item']['can_close_request']);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.report_uploaded'", [':id' => $id]));
        self::assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.report_ready'", [':id' => $id]));
        $result = $repository->closeRequest($id, $this->version($id), $manager, $expertise, $security);
        self::assertSame($expertise ? 'opinion_preparation' : 'completed', $result['status']);
        $details = $query->findDetails($id, $manager);
        self::assertSame($expertise ? 'protocol' : 'act', $details['item']['route']);
        self::assertSame($security, (bool) $details['item']['security_review_required']);
        self::assertSame($manager, (int) $details['item']['route_selected_by']);
        self::assertNotNull($details['item']['route_selected_at']);
        $audit = json_decode((string) $this->scalar("SELECT payload_json FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.closed'", [':id' => $id]), true);
        self::assertSame($expertise, $audit['expertise_required']);
        self::assertSame($security, $audit['security_required']);
        self::assertSame((int) $report['versionId'], $audit['report_version_id']);
        if ($expertise) {
            self::assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.submitted_for_expertise'", [':id' => $id]));
            $repository->claimExpert($id, $this->version($id), $expert);
            $opinion = $this->documents()->publishOpinion($id, $expert, 'Образец соответствует требованиям.', $this->version($id), new OpinionPdfRenderer());
            self::assertSame($security ? 'security_review' : 'completed', $opinion['status']);
            if ($security) {
                $repository->decideSecurity($id, $officer, 'approve', null, $this->version($id));
            } else {
                self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.opinion_published'", [':id' => $id]));
                // Deleting and republishing an expertise-only opinion must not introduce security.
                $this->documents()->deleteOpinion($id, $this->version($id), $expert, 'Уточнить заключение');
                $revised = $this->documents()->publishOpinion($id, $expert, 'Уточнённое заключение.', $this->version($id), new OpinionPdfRenderer());
                self::assertSame('completed', $revised['status']);
            }
        }
        self::assertSame('completed', $query->findDetails($id, $manager)['item']['status']);
        self::assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.completed'", [':id' => $id]));
        self::assertSame($security ? 1 : 0, (int) $this->scalar('SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id]));
    }

    public function testReportReplacementDeletionAndArchiveProtectClosure(): void
    {
        [$id, $manager, $executor] = $this->fixture();
        $repository = new RequestRepository($this->db());
        $this->start($id, $manager, $executor);
        $this->report($id, $executor);
        $before = $this->version($id);
        $this->report($id, $executor);
        $this->assertHttpFailure(409, fn () => $this->controller($manager, ['lockVersion' => $before])->actionClose($id));
        $this->documents()->deleteReport($id, $this->version($id), $executor, 'Исправить отчёт');
        self::assertSame(0, (int) (new RequestQuery($this->db()))->findDetails($id, $manager)['item']['can_close_request']);
        $this->assertHttpFailure(403, fn () => $this->controller($manager, ['lockVersion' => $this->version($id)])->actionClose($id));
        $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager, false, false);
        $this->documents()->deleteReport($id, $this->version($id), $executor, 'Повторить испытания');
        $this->report($id, $executor);
        // A new closure may select different checks after reopening the report.
        $repository->closeRequest($id, $this->version($id), $manager, true, false);
        self::assertSame('opinion_preparation', (new RequestQuery($this->db()))->findDetails($id, $manager)['item']['status']);
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'in_progress', 'is_archived' => 1], ['id' => $id])->execute();
        $this->assertHttpFailure(403, fn () => $this->controller($manager, ['lockVersion' => $this->version($id)])->actionClose($id));
    }

    public function testLaboratoryManagerIsAnEligibleExecutorWithoutExtraRole(): void
    {
        [$id, $manager, , $expert] = $this->fixture();
        $lab = $this->createUser('closure.lab', 'Руководитель лаборатории');
        $this->grantRole($lab, 'laboratory_manager');
        $query = new RequestQuery($this->db());
        self::assertContains($lab, array_map('intval', array_column($query->findActiveExecutors(), 'id')));
        $repository = new RequestRepository($this->db());
        $repository->assignExecutor($id, $lab, $this->version($id), $manager);
        self::assertSame($lab, (int) $query->findDetails($id, $manager)['item']['executor_id']);
        $repository->startRequest($id, $this->version($id), $lab);
        self::assertSame('in_progress', $this->report($id, $lab)['status']);
        $this->grantRole($lab, 'ic_executor');
        self::assertCount(1, array_filter($query->findActiveExecutors(), static fn (array $user): bool => (int) $user['id'] === $lab));
        $this->db()->createCommand()->update('{{%users}}', ['is_active' => 0], ['id' => $lab])->execute();
        self::assertNotContains($lab, array_map('intval', array_column($query->findActiveExecutors(), 'id')));
        self::assertNotContains($expert, array_map('intval', array_column($query->findActiveExecutors(), 'id')));
    }

    /** @return iterable<string, array{bool}> */
    public static function deletionVersions(): iterable
    {
        yield 'current deletion' => [false];
        yield 'deleted before upgrade' => [true];
    }

    #[DataProvider('deletionVersions')]
    public function testNewReportCycleCannotRevivePreviousOpinionOrSecurityDecision(bool $legacy): void
    {
        [$id, $manager, $executor, $expert, $security] = $this->fixture();
        $repository = new RequestRepository($this->db());
        $this->start($id, $manager, $executor);
        $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager);
        $repository->claimExpert($id, $this->version($id), $expert);
        $opinion = $this->documents()->publishOpinion($id, $expert, 'Исходное заключение.', $this->version($id), new OpinionPdfRenderer());
        $repository->decideSecurity($id, $security, 'approve', null, $this->version($id));
        if ($legacy) {
            // The previous release deleted only the report; its opinion remained active.
            $reportId = (int) $this->scalar("SELECT id FROM {{%request_documents}} WHERE request_id = :id AND document_type = 'report'", [':id' => $id]);
            $now = gmdate('Y-m-d H:i:s');
            $this->db()->createCommand()->update('{{%request_document_versions}}', ['deleted_at' => $now], ['document_id' => $reportId])->execute();
            $this->db()->createCommand()->update('{{%request_documents}}', ['deleted_at' => $now, 'deleted_by' => $executor], ['id' => $reportId])->execute();
            $this->db()->createCommand()->update('{{%requests}}', ['status' => 'in_progress', 'lock_version' => $this->version($id) + 1], ['id' => $id])->execute();
        } else {
            $this->documents()->deleteReport($id, $this->version($id), $executor, 'Повторные испытания');
        }
        $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager, false, false);
        $details = (new RequestQuery($this->db()))->findDetails($id, $manager);
        self::assertNull($details['item']['security_mark']);
        self::assertCount(0, array_filter($details['documents'], static fn (array $doc): bool => $doc['documentType'] === 'opinion'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id]));
        $this->expectException(\App\Domain\Request\RequestNotFound::class);
        $this->documents()->findVersionForDownload((int) $opinion['documentVersionId'], $manager);
    }

    public function testLegacySelectionDoesNotSendNewUploadToExperts(): void
    {
        [$id, $manager, $executor] = $this->fixture();
        $this->db()->createCommand()->update('{{%requests}}', ['route' => 'protocol'], ['id' => $id])->execute();
        $this->start($id, $manager, $executor);
        self::assertSame('in_progress', $this->report($id, $executor)['status']);
        (new RequestRepository($this->db()))->closeRequest($id, $this->version($id), $manager, true, false);
        self::assertSame(0, (int) (new RequestQuery($this->db()))->findDetails($id, $manager)['item']['security_review_required']);
    }

    public function testSecurityDecisionStillRequiresPublishedOpinion(): void
    {
        [$id, $manager, $executor, , $security] = $this->fixture();
        $repository = new RequestRepository($this->db());
        $this->start($id, $manager, $executor);
        $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager);
        // Imported or corrupt status alone must not bypass the document requirement.
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'security_review'], ['id' => $id])->execute();
        try {
            $repository->decideSecurity($id, $security, 'decline', null, $this->version($id));
            self::fail('Opinion is mandatory');
        } catch (SecurityDecisionDenied) {
            self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id]));
            self::assertSame('security_review', $this->scalar('SELECT status FROM {{%requests}} WHERE id = :id', [':id' => $id]));
        }
    }

    public function testRollbackRefusesToDiscardClosureChecks(): void
    {
        [$id, $manager, $executor] = $this->fixture();
        $this->start($id, $manager, $executor);
        $this->report($id, $executor);
        (new RequestRepository($this->db()))->closeRequest($id, $this->version($id), $manager, true, false);
        require_once dirname(__DIR__, 3) . '/migrations/m261005_000002_add_request_routes.php';
        $cache = $this->db()->schemaCache;
        $this->db()->schemaCache = new \yii\caching\ArrayCache();
        try {
            $migration = new \m261005_000002_add_request_routes(['db' => $this->db()]);
        } finally {
            $this->db()->schemaCache = $cache;
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('restore the pre-migration backup');
        $migration->safeDown();
    }

    /** @return iterable<string, array{string}> */
    public static function decisions(): iterable
    {
        yield 'approved' => ['approve'];
        yield 'not approved' => ['decline'];
    }

    #[DataProvider('decisions')]
    public function testAnySecurityDecisionCompletesProtocolAtomically(string $decision): void
    {
        [$id, $manager, $executor, $expert, $security] = $this->fixture();
        $repository = new RequestRepository($this->db());
        $this->start($id, $manager, $executor);
        $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager);
        $repository->claimExpert($id, $this->version($id), $expert);
        $this->documents()->publishOpinion($id, $expert, 'Образец прошёл испытания.', $this->version($id), new OpinionPdfRenderer());
        try {
            $repository->closeRequest($id, $this->version($id), $manager, false, false);
            self::fail('Manager cannot bypass security');
        } catch (RequestRouteDenied) {
            $this->addToAssertionCount(1);
        }
        try {
            $repository->decideSecurity($id, $manager, $decision, null, $this->version($id));
            self::fail('Manager cannot decide for security');
        } catch (SecurityDecisionDenied) {
            $this->addToAssertionCount(1);
        }
        $assignments = $this->db()->createCommand('SELECT * FROM {{%request_assignments}} WHERE request_id = :id ORDER BY id', [':id' => $id])->queryAll();
        $revision = $this->scalar('SELECT revision FROM {{%requests}} WHERE id = :id', [':id' => $id]);
        $version = $this->version($id);
        $result = $repository->decideSecurity($id, $security, $decision, null, $version);
        self::assertSame('completed', $result['status']);
        self::assertSame($version + 1, $result['lockVersion']);
        $check = $this->db()->createCommand('SELECT * FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id])->queryOne();
        self::assertSame($decision, $check['decision']);
        self::assertSame($security, (int) $check['officer_id']);
        self::assertNotEmpty($check['created_at']);
        self::assertSame($assignments, $this->db()->createCommand('SELECT * FROM {{%request_assignments}} WHERE request_id = :id ORDER BY id', [':id' => $id])->queryAll());
        self::assertSame($revision, $this->scalar('SELECT revision FROM {{%requests}} WHERE id = :id', [':id' => $id]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.security_decided'", [':id' => $id]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.completed'", [':id' => $id]));
        $this->expectException(ConcurrentRequestModification::class);
        $repository->decideSecurity($id, $security, $decision, null, $version);
    }
    #[DataProvider('decisions')]
    public function testAdministratorCorrectsCompletedDecisionWithPreservedHistory(string $original): void
    {
        [$id, $manager, $executor, $expert, $security] = $this->fixture();
        $admin = $this->createUser('correction.admin', 'Администратор');
        $this->grantRole($admin, 'administrator');
        $repository = new RequestRepository($this->db());
        $query = new RequestQuery($this->db());
        self::assertSame(0, (int) $query->findDetails($id, $admin)['item']['can_correct_security_decision']);
        $this->start($id, $manager, $executor);
        $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager);
        $repository->claimExpert($id, $this->version($id), $expert);
        $this->documents()->publishOpinion($id, $expert, 'Образец прошёл испытания.', $this->version($id), new OpinionPdfRenderer());
        $repository->decideSecurity($id, $security, $original, 'Первоначальное решение', $this->version($id));
        $version = $this->version($id);
        $snapshot = [];
        foreach (['security_checks', 'request_transitions', 'request_assignments', 'request_documents', 'notification_outbox'] as $table) {
            $snapshot[$table] = $this->db()->createCommand("SELECT * FROM {{%$table}} WHERE request_id = :id ORDER BY id", [':id' => $id])->queryAll();
        }
        $new = $original === 'approve' ? 'decline' : 'approve';
        $body = ['decision' => $new, 'reason' => ' Ошибка выбора ', 'ticketReference' => ' IT-360 ', 'lockVersion' => $version];
        self::assertSame(1, (int) $query->findDetails($id, $admin)['item']['can_correct_security_decision']);
        foreach ([$manager, $executor, $expert, $security] as $other) {
            self::assertSame(0, (int) $query->findDetails($id, $other)['item']['can_correct_security_decision']);
            $this->assertHttpFailure(403, fn () => $this->controller($other, $body)->actionCorrectSecurityDecision($id));
        }
        self::assertArrayHasKey('errors', $this->controller($admin, array_replace($body, ['reason' => ' ']))->actionCorrectSecurityDecision($id));
        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertArrayHasKey('errors', $this->controller($admin, array_replace($body, ['ticketReference' => null]))->actionCorrectSecurityDecision($id));
        self::assertSame(422, Yii::$app->response->statusCode);
        $this->assertHttpFailure(404, fn () => $this->controller($admin, $body)->actionCorrectSecurityDecision($id + 1000000));
        $commandClass = $this->db()->commandClass;
        $this->db()->commandClass = CorrectionAuditFailureCommand::class;
        try {
            $repository->correctSecurityDecision($id, $admin, $new, 'Ошибка', 'IT-360', $version);
            self::fail('Audit failure must roll back the correction');
        } catch (\RuntimeException $error) {
            self::assertSame('controlled correction audit failure', $error->getMessage());
        } finally {
            $this->db()->commandClass = $commandClass;
        }
        self::assertSame($version, $this->version($id));
        self::assertSame($original, $query->findDetails($id, $admin)['item']['security_mark']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM {{%security_decision_corrections}} WHERE security_check_id = :id', [':id' => $snapshot['security_checks'][0]['id']]));
        $result = $this->controller($admin, $body)->actionCorrectSecurityDecision($id);
        self::assertSame('completed', $result['status']);
        self::assertSame($version + 1, $result['lockVersion']);
        $this->assertHttpFailure(409, fn () => $this->controller($admin, $body)->actionCorrectSecurityDecision($id));
        $this->assertHttpFailure(403, fn () => $this->controller($admin, array_replace($body, ['lockVersion' => $version + 1]))->actionCorrectSecurityDecision($id));
        $details = $query->findDetails($id, $admin);
        self::assertSame($new, $details['item']['security_mark']);
        $corrections = array_values(array_filter($details['history'], static fn (array $entry): bool => $entry['action'] === 'correct_security_decision'));
        self::assertCount(1, $corrections);
        self::assertSame('Администратор', $corrections[0]['actorName']);
        self::assertNotEmpty($corrections[0]['occurredAt']);
        self::assertStringContainsString('Ошибка выбора', $corrections[0]['reason']);
        self::assertStringContainsString('IT-360', $corrections[0]['reason']);
        $audit = json_decode((string) $this->scalar("SELECT payload_json FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.security_decision_corrected'", [':id' => $id]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($original, $audit['original_decision']);
        self::assertSame($original, $audit['previous_decision']);
        self::assertSame($new, $audit['decision']);
        self::assertSame('Ошибка выбора', $audit['reason']);
        self::assertSame('IT-360', $audit['ticket_reference']);
        $repository->correctSecurityDecision($id, $admin, $original, 'Повторная проверка', 'IT-361', $version + 1);
        self::assertSame($original, $query->findDetails($id, $admin)['item']['security_mark']);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM {{%security_decision_corrections}} WHERE security_check_id = :id', [':id' => $snapshot['security_checks'][0]['id']]));
        foreach ($snapshot as $table => $rows) {
            self::assertSame($rows, $this->db()->createCommand("SELECT * FROM {{%$table}} WHERE request_id = :id ORDER BY id", [':id' => $id])->queryAll(), $table);
        }
        require_once dirname(__DIR__, 3) . '/migrations/m261005_000003_create_security_decision_corrections.php';
        $cache = $this->db()->schemaCache;
        $this->db()->schemaCache = new \yii\caching\ArrayCache();
        try {
            $migration = new \m261005_000003_create_security_decision_corrections(['db' => $this->db()]);
        } finally {
            $this->db()->schemaCache = $cache;
        }
        try {
            $migration->safeDown();
            self::fail('Used correction history must survive rollback attempts');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('restore the pre-migration backup', $error->getMessage());
        }
        $this->db()->createCommand()->update('{{%requests}}', ['is_archived' => 1], ['id' => $id])->execute();
        self::assertSame(0, (int) $query->findDetails($id, $admin)['item']['can_correct_security_decision']);
        $this->expectException(SecurityDecisionDenied::class);
        $repository->correctSecurityDecision($id, $admin, $new, 'Архив', 'IT-362', $version + 2);
    }

    /** @return iterable<string, array{bool}> */
    public static function revokedAccess(): iterable
    {
        yield 'role revoked' => [false];
        yield 'user disabled' => [true];
    }

    #[DataProvider('revokedAccess')]
    public function testCorrectionChecksCurrentAccessInsteadOfTransactionSnapshot(bool $disable): void
    {
        $other = new \yii\db\Connection([
            'dsn' => $this->db()->dsn, 'username' => $this->db()->username,
            'password' => $this->db()->password, 'charset' => 'utf8mb4',
        ]);
        $now = gmdate('Y-m-d H:i:s');
        $other->createCommand()->insert('{{%users}}', [
            'ad_login' => 'correction-race-' . bin2hex(random_bytes(6)), 'display_name' => 'Администратор',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ])->execute();
        $admin = (int) $other->getLastInsertID();
        $this->externalActors[] = $admin;
        $roleId = (int) $other->createCommand("SELECT id FROM {{%roles}} WHERE code = 'administrator'")->queryScalar();
        $other->createCommand()->insert('{{%user_roles}}', ['user_id' => $admin, 'role_id' => $roleId, 'created_at' => $now])->execute();
        try {
            [$id, $manager, $executor, $expert, $security] = $this->fixture();
            $repository = new RequestRepository($this->db());
            $this->start($id, $manager, $executor);
            $this->report($id, $executor);
            $repository->closeRequest($id, $this->version($id), $manager);
            $repository->claimExpert($id, $this->version($id), $expert);
            $this->documents()->publishOpinion($id, $expert, 'Образец прошёл испытания.', $this->version($id), new OpinionPdfRenderer());
            $repository->decideSecurity($id, $security, 'approve', null, $this->version($id));
            $version = $this->version($id);
            // The HTTP idempotency transaction can already have a consistent-read snapshot.
            self::assertSame(1, (int) $this->scalar('SELECT is_active FROM {{%users}} WHERE id = :id', [':id' => $admin]));
            self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :id', [':id' => $admin]));
            if ($disable) {
                $other->createCommand()->update('{{%users}}', ['is_active' => 0], ['id' => $admin])->execute();
            } else {
                $other->createCommand()->delete('{{%user_roles}}', ['user_id' => $admin])->execute();
            }
            try {
                $repository->correctSecurityDecision($id, $admin, 'decline', 'Ошибка', 'IT-360', $version);
                self::fail('Revoked access must not be read from the old snapshot');
            } catch (SecurityDecisionDenied $error) {
                self::assertSame('SEC-006', $error->ruleId);
            }
            self::assertSame($version, $this->version($id));
            self::assertSame('approve', (new RequestQuery($this->db()))->findDetails($id, $manager)['item']['security_mark']);
        } finally {
            $other->close();
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function opinionDeleters(): iterable
    {
        yield 'manager before security' => ['ic_manager', false];
        yield 'other expert after security' => ['expert', true];
    }

    #[DataProvider('opinionDeleters')]
    public function testOpinionDeletionReopensAndRepublishesWithoutRevivingOldDecisions(string $role, bool $completed): void
    {
        [$id, $manager, $executor, $expert, $security] = $this->fixture();
        $actor = $this->createUser('opinion.deleter', 'Автор удаления');
        $this->grantRole($actor, $role);
        $admin = $this->createUser('opinion.admin', 'Администратор');
        $this->grantRole($admin, 'administrator');
        $repository = new RequestRepository($this->db());
        $query = new RequestQuery($this->db());
        $this->start($id, $manager, $executor);
        $report = $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager);
        $repository->claimExpert($id, $this->version($id), $expert);
        $opinion = $this->documents()->publishOpinion($id, $expert, 'Образец прошёл испытания.', $this->version($id), new OpinionPdfRenderer());
        $token = str_repeat('a', 64);
        $this->db()->createCommand()->insert('{{%document_download_links}}', [
            'document_version_id' => $opinion['documentVersionId'], 'token_hash' => hash('sha256', $token), 'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
        if ($completed) {
            $repository->decideSecurity($id, $security, 'approve', null, $this->version($id));
            $repository->correctSecurityDecision($id, $admin, 'decline', 'Ошибочное решение', 'IT-1', $this->version($id));
        }
        $checks = $this->db()->createCommand('SELECT * FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id])->queryAll();
        self::assertSame(1, (int) $query->findDetails($id, $actor)['item']['can_delete_opinion']);
        $before = $this->version($id);
        $response = $this->controller($actor, ['lockVersion' => $before, 'reason' => 'Ошибочное заключение'])->actionDeleteOpinion($id);
        self::assertSame(['status' => 'opinion_preparation', 'lockVersion' => $before + 1], $response);
        $details = $query->findDetails($id, $manager);
        self::assertNull($details['item']['security_mark']);
        self::assertSame(0, (int) $details['item']['can_delete_opinion']);
        self::assertSame(0, (int) $query->findDetails($id, $admin)['item']['can_correct_security_decision']);
        self::assertSame($checks, $this->db()->createCommand('SELECT * FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id])->queryAll());
        $events = array_values(array_filter($details['history'], static fn (array $event): bool => $event['action'] === 'delete_opinion'));
        self::assertCount(1, $events);
        self::assertSame('Ошибочное заключение', $events[0]['reason']);
        self::assertSame('Автор удаления', $events[0]['actorName']);
        self::assertFalse($this->documents()->findVersionByToken($token));
        self::assertSame((int) $report['versionId'], (int) $this->documents()->findVersionForDownload((int) $report['versionId'], $manager)['id']);
        $this->assertHttpFailure(403, fn () => $this->controller($admin, [
            'lockVersion' => $this->version($id), 'decision' => 'approve', 'reason' => 'Нельзя восстановить решение', 'ticketReference' => 'IT-2',
        ])->actionCorrectSecurityDecision($id));
        $next = $this->documents()->publishOpinion($id, $expert, 'Исправленное заключение эксперта.', $this->version($id), new OpinionPdfRenderer());
        self::assertSame(2, $next['revision']);
        self::assertSame('security_review', $next['status']);
        self::assertNull($query->findDetails($id, $manager)['item']['security_mark']);
        self::assertFalse($this->documents()->findVersionByToken($token));
        try {
            $this->documents()->findVersionForDownload((int) $opinion['documentVersionId'], $manager);
            self::fail('Deleted version must stay inaccessible');
        } catch (\App\Domain\Request\RequestNotFound) {
            self::assertNotEmpty($this->documents()->findVersionForDownload((int) $next['documentVersionId'], $manager));
        }
        $repository->decideSecurity($id, $security, 'approve', null, $this->version($id));
        self::assertSame('approve', $query->findDetails($id, $manager)['item']['security_mark']);
        $page = $query->findPage($manager, 1, 100, 'all', null, '', 'desc');
        $row = array_values(array_filter($page['items'], static fn (array $row): bool => (int) $row['id'] === $id))[0];
        self::assertSame('approve', $row['security_mark']);
        self::assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.opinion_deleted'", [':id' => $id]));
    }

    public function testOpinionDeletionRejectsWrongRoleStaleVersionMissingReasonAndArchive(): void
    {
        [$id, $manager, $executor, $expert] = $this->fixture();
        $repository = new RequestRepository($this->db());
        $this->start($id, $manager, $executor);
        $this->report($id, $executor);
        $repository->closeRequest($id, $this->version($id), $manager);
        $repository->claimExpert($id, $this->version($id), $expert);
        $this->documents()->publishOpinion($id, $expert, 'Образец прошёл испытания.', $this->version($id), new OpinionPdfRenderer());
        $version = $this->version($id);
        $lab = $this->createUser('delete.lab', 'Руководитель лаборатории');
        $this->grantRole($lab, 'laboratory_manager');
        foreach ([$executor, $lab] as $actor) {
            self::assertSame(0, (int) (new RequestQuery($this->db()))->findDetails($id, $actor)['item']['can_delete_opinion']);
            $this->assertHttpFailure(403, fn () => $this->controller($actor, ['lockVersion' => $version, 'reason' => 'Ошибка'])->actionDeleteOpinion($id));
        }
        $this->assertHttpFailure(409, fn () => $this->controller($manager, ['lockVersion' => $version - 1, 'reason' => 'Ошибка'])->actionDeleteOpinion($id));
        self::assertArrayHasKey('errors', $this->controller($manager, ['lockVersion' => $version, 'reason' => '  '])->actionDeleteOpinion($id));
        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertSame($version, $this->version($id));
        $this->db()->createCommand()->update('{{%requests}}', ['is_archived' => 1], ['id' => $id])->execute();
        $this->assertHttpFailure(403, fn () => $this->controller($manager, ['lockVersion' => $version, 'reason' => 'Ошибка'])->actionDeleteOpinion($id));
    }
}
