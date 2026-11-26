<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\RequestRouteDenied;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Request\RequestRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

/** HTTP idempotency can establish a repeatable-read snapshot before closure. */
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
        $repository = new RequestRepository($this->writer);
        $repository->assignExecutor($this->requestId, $this->users['ic_executor'], 1, $this->users['ic_manager']);
        $repository->startRequest($this->requestId, 2, $this->users['ic_executor']);
        $path = $this->storageRoot . '/report.pdf';
        file_put_contents($path, '%PDF-1.4 role snapshot');
        (new DocumentRepository($this->writer, new DocumentStorage($this->storageRoot)))->uploadReport($this->requestId, $this->users['ic_executor'], 'report.pdf', 'application/pdf', (int) filesize($path), $path);
        $this->reader->beginTransaction();
        self::assertSame(1, (int) $this->reader->createCommand('SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :id', [':id' => $this->users['ic_manager']])->queryScalar());
        if ($disabled) {
            $this->writer->createCommand()->update('{{%users}}', ['is_active' => 0], ['id' => $this->users['ic_manager']])->execute();
        } else {
            $this->writer->createCommand()->delete('{{%user_roles}}', ['user_id' => $this->users['ic_manager']])->execute();
        }
        $this->expectException(RequestRouteDenied::class);
        (new RequestRepository($this->reader))->closeRequest($this->requestId, 4, $this->users['ic_manager'], true, false);
    }

    #[DataProvider('variants')]
    public function testCurrentReportIsReadAfterAnEarlierSnapshot(bool $deleted): void
    {
        $repository = new RequestRepository($this->writer);
        $id = $this->requestId;
        $executor = $this->users['ic_executor'];
        $manager = $this->users['ic_manager'];
        $repository->assignExecutor($id, $executor, 1, $manager);
        $repository->startRequest($id, 2, $executor);
        $path = $this->storageRoot . '/report.pdf';
        file_put_contents($path, '%PDF-1.4 snapshot test');
        $documents = new DocumentRepository($this->writer, new DocumentStorage($this->storageRoot));
        if ($deleted) {
            $documents->uploadReport($id, $executor, 'report.pdf', 'application/pdf', (int) filesize($path), $path);
        }
        $this->reader->beginTransaction();
        self::assertSame($deleted ? 1 : 0, (int) $this->reader->createCommand(
            'SELECT COUNT(*) FROM {{%request_documents}} WHERE request_id = :id',
            [':id' => $id],
        )->queryScalar());
        if ($deleted) {
            $documents->deleteReport($id, 4, $executor, 'Исправить отчёт');
            $this->expectException(RequestRouteDenied::class);
            (new RequestRepository($this->reader))->closeRequest($id, 5, $manager);
        } else {
            $documents->uploadReport($id, $executor, 'report.pdf', 'application/pdf', (int) filesize($path), $path);
            $result = (new RequestRepository($this->reader))->closeRequest($id, 4, $manager);
            self::assertSame('opinion_preparation', $result['status']);
        }
    }

    public function testResumeWithReportStillRequiresManagerClosure(): void
    {
        $repository = new RequestRepository($this->writer);
        $id = $this->requestId;
        $executor = $this->users['ic_executor'];
        $manager = $this->users['ic_manager'];
        $repository->assignExecutor($id, $executor, 1, $manager);
        $repository->startRequest($id, 2, $executor);
        $path = $this->storageRoot . '/report.pdf';
        file_put_contents($path, '%PDF-1.4 snapshot test');
        (new DocumentRepository($this->writer, new DocumentStorage($this->storageRoot)))->uploadReport($id, $executor, 'report.pdf', 'application/pdf', (int) filesize($path), $path);
        $this->writer->createCommand()->update('{{%requests}}', ['route' => 'protocol'], ['id' => $id])->execute();
        $repository->suspendRequest($id, 4, $executor, 'Ожидание');
        self::assertSame('in_progress', $repository->resumeRequest($id, 5, $executor)['status']);
        self::assertSame('opinion_preparation', $repository->closeRequest($id, 6, $manager)['status']);
    }

    private function connection(): Connection
    {
        return new Connection([
            'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
            'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4',
        ]);
    }
}
