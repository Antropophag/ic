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

final class RequestRoutesTest extends IntegrationTestCase
{
    private string $storageRoot;
    private string $pdf;

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
            parent::tearDown();
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
}
