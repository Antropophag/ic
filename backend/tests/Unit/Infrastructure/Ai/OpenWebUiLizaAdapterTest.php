<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\OpenWebUiLizaAdapter;
use App\Application\Ai\TechnicalSpecificationFile;
use App\Infrastructure\Ai\AiFileCleanupQueue;
use PHPUnit\Framework\TestCase;

final class OpenWebUiLizaAdapterTest extends TestCase
{
    public function testBuildsSocketBackedPayloadWithoutCredential(): void
    {
        $transport = new RecordingOpenWebUiTransport();
        $reply = (new OpenWebUiLizaAdapter($transport, 'ЛИЗА'))->start('Текст ТЗ');

        self::assertSame('socket-sid', $transport->payload['session_id']);
        self::assertSame([$transport->payload['id']], array_values($transport->payload['message_ids']));
        self::assertNull($transport->payload['parent_id']);
        self::assertSame('Текст ТЗ', $transport->payload['user_message']['content']);
        self::assertSame([$transport->payload['id']], $transport->payload['user_message']['childrenIds']);
        self::assertStringStartsWith('server-chat-', $transport->payload['chat_id']);
        self::assertSame($transport->payload['chat_id'], $reply->chatId);
        self::assertSame('Текст ТЗ', $transport->chat['messages'][0]['content']);
        self::assertSame($transport->payload['id'], $transport->chat['messages'][1]['id']);
        self::assertStringNotContainsString('secret-token', json_encode($transport->payload, JSON_THROW_ON_ERROR));
        self::assertSame(1, $transport->disconnects);
    }

    public function testEveryStartCreatesANewRootChat(): void
    {
        $transport = new RecordingOpenWebUiTransport();
        (new OpenWebUiLizaAdapter($transport, 'ЛИЗА'))->start('Черновик');

        self::assertStringStartsWith('server-chat-', $transport->payload['chat_id']);
        self::assertNull($transport->payload['parent_id']);
        self::assertNull($transport->payload['user_message']['parentId']);
        self::assertNotSame([], $transport->chat);
        self::assertSame(1, $transport->disconnects);
    }

    public function testUploadsFileAttachesItToChatAndDeletesTemporaryCopy(): void
    {
        $transport = new RecordingOpenWebUiTransport();
        $file = new TechnicalSpecificationFile('тз.docx', 'application/vnd.test', '/storage/tz.docx', 14);

        (new OpenWebUiLizaAdapter($transport, 'ЛИЗА'))->start('Проанализируй файл', $file);

        self::assertSame([['name' => 'тз.docx', 'mimeType' => 'application/vnd.test', 'path' => '/storage/tz.docx']], $transport->uploads);
        self::assertSame('uploaded-file-1', $transport->payload['files'][0]['file']['id']);
        self::assertSame('uploaded-file-1', $transport->payload['user_message']['files'][0]['file']['id']);
        self::assertSame('тз.docx', $transport->payload['user_message']['files'][0]['name']);
        self::assertSame('uploaded', $transport->payload['user_message']['files'][0]['status']);
        self::assertSame($transport->payload['user_message']['files'], $transport->chat['messages'][0]['files']);
        self::assertSame(['uploaded-file-1'], $transport->deletedFileIds);
    }

    public function testSeparateAnalysesDoNotShareChatState(): void
    {
        $firstTransport = new RecordingOpenWebUiTransport();
        $secondTransport = new RecordingOpenWebUiTransport();

        $first = (new OpenWebUiLizaAdapter($firstTransport, 'ЛИЗА'))->start('Первое ТЗ');
        $second = (new OpenWebUiLizaAdapter($secondTransport, 'ЛИЗА'))->start('Второе ТЗ');

        self::assertNotSame($first->chatId, $second->chatId);
        self::assertNotSame($first->messageId, $second->messageId);
        self::assertSame(1, $firstTransport->disconnects);
        self::assertSame(1, $secondTransport->disconnects);
    }

    public function testDisconnectsWhenCompletionFails(): void
    {
        $transport = new RecordingOpenWebUiTransport(new \RuntimeException('socket failed'));
        $file = new TechnicalSpecificationFile('тз.docx', 'application/vnd.test', '/storage/tz.docx', 14);

        $this->expectException(\RuntimeException::class);
        try {
            (new OpenWebUiLizaAdapter($transport, 'ЛИЗА'))->start('Текст ТЗ', $file);
        } finally {
            self::assertSame(['uploaded-file-1'], $transport->deletedFileIds);
            self::assertSame(1, $transport->disconnects);
        }
    }

    public function testFailedDeleteKeepsResultAndSchedulesSafeRetry(): void
    {
        $transport = new RecordingOpenWebUiTransport(null, new \RuntimeException('delete failed with document text'));
        $queue = new class implements AiFileCleanupQueue {
            /** @var list<array{string, string}> */
            public array $scheduled = [];
            public function schedule(string $externalFileId, \Throwable $error): void
            {
                $this->scheduled[] = [$externalFileId, $error::class];
            }
        };
        $file = new TechnicalSpecificationFile('тз.docx', 'application/vnd.test', '/storage/tz.docx', 14);

        $reply = (new OpenWebUiLizaAdapter($transport, 'ЛИЗА', $queue))->start('Секретный текст ТЗ', $file);

        self::assertSame('Ответ', $reply->content);
        self::assertSame([['uploaded-file-1', \RuntimeException::class]], $queue->scheduled);
        self::assertSame(1, $transport->disconnects);
    }
}
