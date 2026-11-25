<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\RequestRoute;
use App\Domain\Request\RequestRouteDenied;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Document\OpinionPdfRenderer;
use App\Infrastructure\Request\RequestRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

/** HTTP idempotency can establish a repeatable-read snapshot before a route command. */
final class RequestRouteSnapshotTest extends TestCase
{
    private Connection $writer;
    private Connection $reader;
    /** @var array<string, int> */
    private array $users = [];
    private int $requestId;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->writer = $this->connection();
        $this->reader = $this->connection();
        $this->storageRoot = sys_get_temp_dir() . '/route-snapshot-' . bin2hex(random_bytes(5));
        mkdir($this->storageRoot, 0700);
        $now = gmdate('Y-m-d H:i:s');
        foreach (['employee', 'ic_manager', 'ic_executor', 'expert'] as $role) {
            $this->writer->createCommand()->insert('{{%users}}', [
                'ad_login' => 'route-snapshot-' . bin2hex(random_bytes(5)), 'display_name' => $role,
                'department' => 'Учебное подразделение', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ])->execute();
            $this->users[$role] = (int) $this->writer->getLastInsertID();
            $roleId = $this->writer->createCommand('SELECT id FROM {{%roles}} WHERE code = :role', [':role' => $role])->queryScalar();
            $this->writer->createCommand()->insert('{{%user_roles}}', [
                'user_id' => $this->users[$role], 'role_id' => $roleId, 'created_at' => $now,
            ])->execute();
        }
        $input = new CreateRequestInput();
        $input->load(['productName' => 'Учебный образец', 'manufacturer' => 'Учебный завод', 'supplier' => 'Учебный поставщик', 'sampleQuantity' => 1, 'testMethod' => 'Учебная программа'], '');
        self::assertTrue($input->validate());
        $this->requestId = (int) (new RequestRepository($this->writer))->create($input, $this->users['employee'])['id'];
    }

    protected function tearDown(): void
    {
        $this->reader->getTransaction()?->rollBack();
        $this->reader->close();
        $this->writer->createCommand()->delete('{{%audit_events}}', ['entity_type' => 'request', 'entity_id' => $this->requestId])->execute();
        foreach (['request_transitions', 'expert_opinions', 'request_documents', 'request_assignments'] as $table) {
            $this->writer->createCommand()->delete('{{%' . $table . '}}', ['request_id' => $this->requestId])->execute();
        }
        $this->writer->createCommand()->delete('{{%requests}}', ['id' => $this->requestId])->execute();
        foreach ($this->users as $id) {
            $this->writer->createCommand()->delete('{{%user_roles}}', ['user_id' => $id])->execute();
            $this->writer->createCommand()->delete('{{%users}}', ['id' => $id])->execute();
        }
        $this->writer->close();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->storageRoot);
    }

    /** @return iterable<string, array{bool}> */
    public static function variants(): iterable
    {
        yield 'first' => [false];
        yield 'second' => [true];
    }

    #[DataProvider('variants')]
    public function testRevokedRoleOrDisabledActorCannotUseOldSnapshot(bool $disabled): void
    {
        $this->reader->beginTransaction();
        self::assertSame(1, (int) $this->reader->createCommand('SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :id', [':id' => $this->users['ic_manager']])->queryScalar());
        if ($disabled) {
            $this->writer->createCommand()->update('{{%users}}', ['is_active' => 0], ['id' => $this->users['ic_manager']])->execute();
        } else {
            $this->writer->createCommand()->delete('{{%user_roles}}', ['user_id' => $this->users['ic_manager']])->execute();
        }
        $this->expectException(RequestRouteDenied::class);
        (new RequestRepository($this->reader))->chooseRoute($this->requestId, RequestRoute::Protocol, 1, $this->users['ic_manager'], false);
    }

    #[DataProvider('variants')]
    public function testPublicationOrCompletionIsVisibleAfterDocumentsAreDeleted(bool $act): void
    {
        $id = $this->requestId;
        $manager = $this->users['ic_manager'];
        $executor = $this->users['ic_executor'];
        $expert = $this->users['expert'];
        $repository = new RequestRepository($this->writer);
        $repository->chooseRoute($id, $act ? RequestRoute::Act : RequestRoute::Protocol, 1, $manager);
        $repository->assignExecutor($id, $executor, 2, $manager);
        $repository->startRequest($id, 3, $executor);
        $path = $this->storageRoot . '/report.pdf';
        file_put_contents($path, '%PDF-1.4 snapshot test');
        $documents = new DocumentRepository($this->writer, new DocumentStorage($this->storageRoot));
        $documents->uploadReport($id, $executor, 'report.pdf', 'application/pdf', (int) filesize($path), $path);
        if (!$act) {
            $repository->claimExpert($id, 5, $expert);
        }
        $this->reader->beginTransaction();
        self::assertSame(0, (int) $this->reader->createCommand('SELECT COUNT(*) FROM {{%expert_opinions}} WHERE request_id = :id', [':id' => $id])->queryScalar());
        if ($act) {
            $repository->completeAct($id, 5, $manager);
            $documents->deleteReport($id, 6, $executor, 'Исправить отчёт');
        } else {
            $documents->publishOpinion($id, $expert, 'Учебное заключение по испытаниям', 6, new OpinionPdfRenderer());
            $documents->deleteOpinion($id, 7, $expert, 'Исправить заключение');
        }
        $version = (int) $this->writer->createCommand('SELECT lock_version FROM {{%requests}} WHERE id = :id', [':id' => $id])->queryScalar();
        $this->expectException(RequestRouteDenied::class);
        (new RequestRepository($this->reader))->chooseRoute($id, RequestRoute::Protocol, $version, $manager, false);
    }

    #[DataProvider('variants')]
    public function testNewReportIsVisibleWhenChangingRouteOrResuming(bool $resume): void
    {
        $id = $this->requestId;
        $manager = $this->users['ic_manager'];
        $executor = $this->users['ic_executor'];
        $repository = new RequestRepository($this->writer);
        $repository->chooseRoute($id, RequestRoute::Act, 1, $manager);
        $repository->assignExecutor($id, $executor, 2, $manager);
        $repository->startRequest($id, 3, $executor);
        $this->reader->beginTransaction();
        self::assertSame(0, (int) $this->reader->createCommand('SELECT COUNT(*) FROM {{%request_documents}} WHERE request_id = :id', [':id' => $id])->queryScalar());
        $path = $this->storageRoot . '/report.pdf';
        file_put_contents($path, '%PDF-1.4 snapshot test');
        (new DocumentRepository($this->writer, new DocumentStorage($this->storageRoot)))->uploadReport($id, $executor, 'report.pdf', 'application/pdf', (int) filesize($path), $path);
        if ($resume) {
            $repository->suspendRequest($id, 5, $executor, 'Ожидание');
            $repository->chooseRoute($id, RequestRoute::Protocol, 6, $manager);
            $result = (new RequestRepository($this->reader))->resumeRequest($id, 7, $executor);
        } else {
            $result = (new RequestRepository($this->reader))->chooseRoute($id, RequestRoute::Protocol, 5, $manager);
        }
        self::assertSame('opinion_preparation', $result['status']);
    }

    private function connection(): Connection
    {
        return new Connection([
            'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
            'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4',
        ]);
    }
}
