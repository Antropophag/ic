<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Document;

use App\Application\Document\TestActDocumentService;
use App\Application\Document\TestActInput;
use App\Infrastructure\Document\TestActDocumentGenerator;
use PHPUnit\Framework\TestCase;
use yii\db\Command;
use yii\db\Connection;

final class TestActDocumentServiceTest extends TestCase
{
    public function testPreparesAndGeneratesDocumentFromAuthorizedRequest(): void
    {
        $service = new TestActDocumentService(
            $this->connection([
                'number' => 42,
                'productName' => 'Образец',
                'testMethod' => 'Проверка маркировки',
                'status' => 'in_progress',
                'isArchived' => 0,
                'actorName' => 'Петров Пётр Петрович',
                'actorPosition' => 'Инженер-испытатель',
                'isExecutor' => 1,
                'isManager' => 0,
                'hasActiveReport' => 0,
            ]),
            new TestActDocumentGenerator(),
        );

        $prepared = $service->prepare(
            10,
            7,
            new \DateTimeImmutable('2026-08-11', new \DateTimeZone('Europe/Moscow')),
        );
        self::assertSame('test_act', $prepared['documentType']);
        self::assertSame('42', $prepared['actNumber']);
        self::assertSame('11.08.2026', $prepared['actDate']);
        self::assertSame('Образец', $prepared['sampleName']);
        self::assertSame('Заявка № 42', $prepared['basis']);
        self::assertSame('Проверка маркировки', $prepared['testMethod']);
        self::assertSame('Иванов И.И.', $prepared['approverName']);
        self::assertSame('manager@example.test', $prepared['contactEmail']);

        $input = new TestActInput();
        $input->setAttributes([
            'actNumber' => '42-А',
            'actDate' => '11.08.2026',
            'basis' => 'Заявка № 42',
            'result' => 'Испытания пройдены.',
        ]);
        self::assertTrue($input->validate());

        $document = $service->generate(10, 7, $input);
        self::assertSame('Акт_испытаний_заявка_42.docx', $document->fileName);
        self::assertStringStartsWith('PK', $document->content);
    }

    /** @param array<string, int|string|null> $request */
    private function connection(array $request): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createCommand'])
            ->getMock();
        $connection->method('createCommand')->willReturnCallback(
            function (string $sql) use ($request): Command {
                $command = $this->getMockBuilder(Command::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['queryOne', 'queryAll'])
                    ->getMock();
                if (str_contains($sql, 'manager_role')) {
                    $command->method('queryOne')->willReturn($request);
                } else {
                    $command->method('queryAll')->willReturn([[
                        'name' => 'Иванов Иван Иванович',
                        'email' => 'manager@example.test',
                    ]]);
                }

                return $command;
            },
        );

        return $connection;
    }
}
