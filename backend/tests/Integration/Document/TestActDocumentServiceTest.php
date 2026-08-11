<?php

declare(strict_types=1);

namespace Tests\Integration\Document;

use App\Application\Document\TestActDocumentService;
use App\Application\Document\TestActInput;
use App\Application\Document\TestActConfigurationError;
use App\Application\Request\CreateRequestInput;
use App\Domain\Request\ReportDenied;
use App\Domain\Request\RequestNotFound;
use App\Infrastructure\Clock;
use App\Infrastructure\Document\TestActDocumentGenerator;
use App\Infrastructure\Request\RequestRepository;
use Tests\Integration\IntegrationTestCase;

final class TestActDocumentServiceTest extends IntegrationTestCase
{
    public function testAssignedExecutorGetsPreparedFieldsAndGeneratesWithoutChangingRequest(): void
    {
        [$requestId, $requestNumber, $executor] = $this->inProgressRequest('executor');
        $service = $this->service();
        $before = $this->requestState($requestId);

        $prepared = $service->prepare(
            $requestId,
            $executor,
            new \DateTimeImmutable('2026-08-11', new \DateTimeZone('Europe/Moscow')),
        );
        self::assertSame('test_act', $prepared['documentType']);
        self::assertSame((string) $requestNumber, $prepared['actNumber']);
        self::assertSame('11.08.2026', $prepared['actDate']);
        self::assertSame('Испытуемый образец Кириллица', $prepared['sampleName']);
        self::assertSame("Заявка № {$requestNumber}", $prepared['basis']);
        self::assertSame('Проверка маркировки', $prepared['testMethod']);
        self::assertSame('Иванов И.И.', $prepared['approverName']);
        self::assertSame('test.act.manager@example.invalid', $prepared['contactEmail']);

        $document = $service->generate($requestId, $executor, $this->validInput($requestNumber));
        self::assertSame("Акт_испытаний_заявка_{$requestNumber}.docx", $document->fileName);
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $document->mimeType);
        self::assertStringStartsWith('PK', $document->content);
        $documentXml = $this->documentXml($document->content);
        self::assertStringContainsString('Иванов И.И.', $documentXml);
        self::assertStringContainsString('Исполнитель И.И.', $documentXml);
        self::assertStringContainsString('Инженер-испытатель', $documentXml);
        self::assertSame($before, $this->requestState($requestId));
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%request_documents}} WHERE request_id = :id',
            [':id' => $requestId],
        ));
    }

    public function testManagerCanGenerateActForAssignedRequest(): void
    {
        [$requestId, $requestNumber, , $manager] = $this->inProgressRequest('manager');

        $document = $this->service()->generate($requestId, $manager, $this->validInput($requestNumber));

        self::assertStringStartsWith('PK', $document->content);
    }

    public function testUnrelatedEmployeeCannotPrepareAct(): void
    {
        [$requestId] = $this->inProgressRequest('prepare.denied');
        $outsider = $this->createUser('test.act.prepare.outsider', 'Посторонний сотрудник');

        $this->expectException(ReportDenied::class);
        $this->service()->prepare($requestId, $outsider);
    }

    public function testUnrelatedEmployeeCannotGenerateAct(): void
    {
        [$requestId, $requestNumber] = $this->inProgressRequest('generate.denied');
        $outsider = $this->createUser('test.act.generate.outsider', 'Посторонний сотрудник');

        $this->expectException(ReportDenied::class);
        $this->service()->generate($requestId, $outsider, $this->validInput($requestNumber));
    }

    public function testMissingRequestIsReported(): void
    {
        $actor = $this->createUser('test.act.missing', 'Исполнитель отсутствующей заявки');

        $this->expectException(RequestNotFound::class);
        $this->service()->prepare(PHP_INT_MAX, $actor);
    }

    public function testAmbiguousTestCenterManagerIsReported(): void
    {
        [$requestId, , $executor] = $this->inProgressRequest('ambiguous.manager');
        $secondManager = $this->createUser('test.act.second.manager', 'Второй руководитель ИЦ');
        $this->grantRole($secondManager, 'ic_manager');

        $this->expectException(TestActConfigurationError::class);
        $this->service()->prepare($requestId, $executor);
    }

    public function testManagerWithoutEmailIsReported(): void
    {
        [$requestId, , $executor, $manager] = $this->inProgressRequest('manager.without.email');
        $this->db()->createCommand()->update('{{%users}}', ['email' => null], ['id' => $manager])->execute();

        $this->expectException(TestActConfigurationError::class);
        $this->service()->prepare($requestId, $executor);
    }

    /** @return array{int, int, int, int} */
    private function inProgressRequest(string $marker): array
    {
        $this->db()->createCommand(
            "DELETE ur FROM {{%user_roles}} ur JOIN {{%roles}} role ON role.id = ur.role_id WHERE role.code = 'ic_manager'",
        )->execute();
        $manager = $this->createUser(
            'test.act.manager.' . $marker,
            'Иванов Иван Иванович',
            'test.act.manager@example.invalid',
        );
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser("test.act.initiator.{$marker}", 'Инициатор');
        $executor = $this->createUser("test.act.executor.{$marker}", 'Исполнитель Иван Иванович');
        $this->db()->createCommand()->update(
            '{{%users}}',
            ['position' => 'Инженер-испытатель'],
            ['id' => $executor],
        )->execute();
        $this->grantRole($executor, 'ic_executor');
        $input = new CreateRequestInput();
        $input->productName = 'Испытуемый образец Кириллица';
        $input->manufacturer = 'Производитель';
        $input->supplier = 'Поставщик';
        $input->sampleQuantity = 2;
        $input->testMethod = 'Проверка маркировки';
        $request = (new RequestRepository($this->db()))->create($input, $initiator);
        $requestId = (int) $request['id'];
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'in_progress'], ['id' => $requestId])->execute();
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $executor,
            'assigned_by' => $initiator,
            'valid_from' => Clock::now(),
        ])->execute();

        return [$requestId, (int) $request['number'], $executor, $manager];
    }

    private function validInput(int $requestNumber): TestActInput
    {
        $input = new TestActInput();
        $input->setAttributes([
            'actNumber' => (string) $requestNumber,
            'actDate' => '11.08.2026',
            'basis' => "Заявка № {$requestNumber}",
            'result' => 'Маркировка соответствует требованиям.',
        ]);
        self::assertTrue($input->validate());
        return $input;
    }

    /** @return array<string, mixed> */
    private function requestState(int $requestId): array
    {
        return $this->db()->createCommand(
            'SELECT status, lock_version, updated_at FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
    }

    private function service(): TestActDocumentService
    {
        return new TestActDocumentService($this->db(), new TestActDocumentGenerator());
    }

    private function documentXml(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-act-service-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $content);
            $archive = new \ZipArchive();
            self::assertTrue($archive->open($path));
            $xml = $archive->getFromName('word/document.xml');
            $archive->close();
            self::assertNotFalse($xml);
            return $xml;
        } finally {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-created test fixture
            unlink($path);
        }
    }
}
