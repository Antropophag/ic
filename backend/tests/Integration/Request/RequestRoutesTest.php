<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestRoute;
use App\Domain\Request\RequestRouteDenied;
use App\Domain\Request\SecurityDecisionDenied;
use App\Domain\Request\StartDenied;
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

    private function start(int $id, int $manager, int $executor, RequestRoute $route): void
    {
        $repository = new RequestRepository($this->db());
        $repository->chooseRoute($id, $route, $this->version($id), $manager);
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

    public function testRouteHttpBoundaryMapsValidationPermissionsConflictsAndMissingRequests(): void
    {
        [$id, $manager, $executor, $expert] = $this->fixture();
        self::assertArrayHasKey('errors', $this->controller($manager, ['route' => true, 'lockVersion' => 1])->actionChooseRoute($id));
        self::assertSame(422, Yii::$app->response->statusCode);
        self::assertArrayHasKey('errors', $this->controller($manager, [])->actionCompleteAct($id));
        self::assertSame(422, Yii::$app->response->statusCode);
        $input = ['route' => 'act', 'lockVersion' => 1];
        $this->assertHttpFailure(403, fn () => $this->controller($expert, $input)->actionChooseRoute($id));
        $this->assertHttpFailure(404, fn () => $this->controller($manager, $input)->actionChooseRoute($id + 1000000));
        $chosen = $this->controller($manager, $input)->actionChooseRoute($id);
        self::assertSame('act', $chosen['route']);
        self::assertSame('registered', $chosen['status']);
        $this->assertHttpFailure(409, fn () => $this->controller($manager, $input)->actionChooseRoute($id));
        $repository = new RequestRepository($this->db());
        $repository->assignExecutor($id, $executor, $this->version($id), $manager);
        $repository->startRequest($id, $this->version($id), $executor);
        $this->assertHttpFailure(403, fn () => $this->controller($manager, ['lockVersion' => $this->version($id)])->actionCompleteAct($id));
        $this->report($id, $executor);
        $version = $this->version($id);
        $this->assertHttpFailure(409, fn () => $this->controller($manager, ['lockVersion' => $version - 1])->actionCompleteAct($id));
        $this->assertHttpFailure(404, fn () => $this->controller($manager, ['lockVersion' => $version])->actionCompleteAct($id + 1000000));
        $completed = $this->controller($manager, ['lockVersion' => $version])->actionCompleteAct($id);
        self::assertSame('completed', $completed['status']);
        self::assertSame(4, (int) $this->scalar("SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.route_action_denied'", [':id' => $id]));
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'IC manager' => ['ic_manager'];
        yield 'laboratory manager' => ['laboratory_manager'];
    }

    #[DataProvider('managers')]
    public function testActIsExplicitlyCompletedByEitherManager(string $role): void
    {
        [$id, $manager, $executor, $expert, $security] = $this->fixture($role);
        $repository = new RequestRepository($this->db());
        $query = new RequestQuery($this->db());
        self::assertSame(1, (int) $query->findDetails($id, $manager)['item']['can_choose_route']);
        self::assertSame(0, (int) $query->findDetails($id, $expert)['item']['can_choose_route']);
        self::assertSame(0, (int) $query->findDetails($id, $manager)['item']['can_assign_executor']);
        try {
            $repository->assignExecutor($id, $executor, $this->version($id), $manager);
            self::fail('Route must be selected before executor assignment');
        } catch (\App\Domain\Request\AssignmentDenied $error) {
            self::assertSame('WF-014', $error->ruleId);
        }
        self::assertSame(0, (int) $query->findDetails($id, $executor)['item']['can_start']);
        try {
            $repository->startRequest($id, $this->version($id), $manager);
            self::fail('Missing route must prevent start');
        } catch (StartDenied $error) {
            self::assertSame('WF-014', $error->ruleId);
        }
        $this->start($id, $manager, $executor, RequestRoute::Act);
        try {
            $repository->completeAct($id, $this->version($id), $manager);
            self::fail('Missing report must prevent completion');
        } catch (RequestRouteDenied $error) {
            self::assertSame('WF-015', $error->ruleId);
        }
        $this->report($id, $executor);
        $details = $query->findDetails($id, $manager);
        self::assertSame('in_progress', $details['item']['status']);
        self::assertSame(1, (int) $details['item']['can_complete_act']);
        self::assertSame(0, (int) $details['item']['can_choose_route']);
        self::assertSame($manager, (int) $details['item']['route_selected_by']);
        self::assertNotNull($details['item']['route_selected_at']);
        foreach ([$executor, $expert, $security] as $other) {
            self::assertSame(0, (int) $query->findDetails($id, $other)['item']['can_complete_act']);
            try {
                $repository->completeAct($id, $this->version($id), $other);
                self::fail('Only managers complete acts');
            } catch (RequestRouteDenied) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.report_uploaded'", [':id' => $id]));
        self::assertGreaterThanOrEqual(1, (int) $this->scalar("SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.act_ready'", [':id' => $id]));
        $oldVersion = $this->version($id);
        $this->report($id, $executor);
        try {
            $repository->completeAct($id, $oldVersion, $manager);
            self::fail('Replaced report must invalidate completion');
        } catch (ConcurrentRequestModification) {
            $this->addToAssertionCount(1);
        }
        $result = $repository->completeAct($id, $this->version($id), $manager);
        self::assertSame('completed', $result['status']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM {{%expert_opinions}} WHERE request_id = :id', [':id' => $id]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM {{%request_transitions}} WHERE request_id = :id AND action = 'complete_act' AND actor_id = :actor", [':id' => $id, ':actor' => $manager]));
        $this->expectException(ConcurrentRequestModification::class);
        $repository->completeAct($id, $result['lockVersion'] - 1, $manager);
    }

    public function testRouteChangesFreezeAtFirstReportEvenAfterDeletion(): void
    {
        [$id, $manager, $executor] = $this->fixture();
        $repository = new RequestRepository($this->db());
        $this->start($id, $manager, $executor, RequestRoute::Protocol);
        $before = $this->version($id);
        $repository->chooseRoute($id, RequestRoute::Act, $before, $manager);
        try {
            $repository->chooseRoute($id, RequestRoute::Protocol, $before, $manager);
            self::fail('Concurrent choice must fail');
        } catch (ConcurrentRequestModification) {
            $this->addToAssertionCount(1);
        }
        $this->report($id, $executor);
        $this->documents()->deleteReport($id, $this->version($id), $executor, 'Исправить отчёт');
        self::assertSame(0, (int) (new RequestQuery($this->db()))->findDetails($id, $manager)['item']['can_choose_route']);
        $this->expectException(RequestRouteDenied::class);
        $repository->chooseRoute($id, RequestRoute::Protocol, $this->version($id), $manager);
    }

    public function testMissingOrDeletedReportCannotCompleteAndSelectionCannotBeMadeByExpert(): void
    {
        [$id, $manager, $executor, $expert] = $this->fixture();
        $repository = new RequestRepository($this->db());
        try {
            $repository->chooseRoute($id, RequestRoute::Act, $this->version($id), $expert);
            self::fail('Expert cannot select route');
        } catch (RequestRouteDenied) {
            self::assertNull($this->scalar('SELECT route FROM {{%requests}} WHERE id = :id', [':id' => $id]));
        }
        $this->start($id, $manager, $executor, RequestRoute::Act);
        $this->report($id, $executor);
        $this->documents()->deleteReport($id, $this->version($id), $executor, 'Исправить отчёт');
        self::assertSame(0, (int) (new RequestQuery($this->db()))->findDetails($id, $manager)['item']['can_complete_act']);
        try {
            $repository->completeAct($id, $this->version($id), $manager);
            self::fail('Deleted report cannot complete act');
        } catch (RequestRouteDenied) {
            $this->addToAssertionCount(1);
        }
        $this->report($id, $executor);
        self::assertSame('completed', $repository->completeAct($id, $this->version($id), $manager)['status']);
    }

    public function testProtocolWithoutOpinionCannotAcceptSecurityDecision(): void
    {
        [$id, $manager, $executor, , $security] = $this->fixture();
        $repository = new RequestRepository($this->db());
        $this->start($id, $manager, $executor, RequestRoute::Protocol);
        $this->report($id, $executor);
        // A corrupt/imported workflow state must not bypass the document requirement.
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'security_review'], ['id' => $id])->execute();
        try {
            $repository->decideSecurity($id, $security, 'decline', null, $this->version($id));
            self::fail('Opinion is mandatory');
        } catch (SecurityDecisionDenied) {
            self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM {{%security_checks}} WHERE request_id = :id', [':id' => $id]));
            self::assertSame('security_review', $this->scalar('SELECT status FROM {{%requests}} WHERE id = :id', [':id' => $id]));
        }
    }

    public function testRollbackRefusesToDiscardChosenRoutes(): void
    {
        [$id, $manager] = $this->fixture();
        (new RequestRepository($this->db()))->chooseRoute($id, RequestRoute::Act, $this->version($id), $manager);
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
        $this->start($id, $manager, $executor, RequestRoute::Protocol);
        $this->report($id, $executor);
        $repository->claimExpert($id, $this->version($id), $expert);
        $this->documents()->publishOpinion($id, $expert, 'Образец прошёл испытания.', $this->version($id), new OpinionPdfRenderer());
        try {
            $repository->completeAct($id, $this->version($id), $manager);
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
        $this->start($id, $manager, $executor, RequestRoute::Protocol);
        $this->report($id, $executor);
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
            $this->start($id, $manager, $executor, RequestRoute::Protocol);
            $this->report($id, $executor);
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
}
