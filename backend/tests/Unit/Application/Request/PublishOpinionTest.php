<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\PublishOpinionCommand;
use App\Application\Request\PublishOpinionSnapshot;
use App\Application\Request\UseCase\PublishOpinion;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\OpinionDenied;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\TestCase;

final class PublishOpinionTest extends TestCase
{
    public function testPublishesRenderedOpinionAndReturnsTransitionResult(): void
    {
        $gateway = new InMemoryPublishOpinionGateway($this->snapshot());
        $renderer = new RecordingOpinionRenderer('%PDF opinion');
        $command = new PublishOpinionCommand(41, 7, 'Испытания пройдены успешно.', 3);

        $result = (new PublishOpinion($gateway, $renderer))->execute($command);

        self::assertSame([
            'requestId' => 41, 'revision' => 2, 'documentVersionId' => 73,
            'status' => 'security_review', 'lockVersion' => 4,
        ], $result->toArray());
        self::assertSame('Испытания пройдены успешно.', $renderer->body);
        self::assertSame('%PDF opinion', $gateway->persistedPdf);
        self::assertSame(1, $gateway->transactionCount);
    }

    public function testUnauthorizedActorIsRejectedBeforeRenderingOrPersistence(): void
    {
        $gateway = new InMemoryPublishOpinionGateway($this->snapshot(isCurrentExpert: false));
        $renderer = new RecordingOpinionRenderer('%PDF opinion');

        try {
            (new PublishOpinion($gateway, $renderer))->execute(new PublishOpinionCommand(41, 8, str_repeat('x', 10), 3));
            self::fail('Expected publication denial.');
        } catch (OpinionDenied $error) {
            self::assertSame('DOC-005', $error->ruleId);
            self::assertNull($renderer->body);
            self::assertNull($gateway->persistedPdf);
        }
    }

    public function testInvalidStateIsRejectedBeforeRenderingOrPersistence(): void
    {
        $gateway = new InMemoryPublishOpinionGateway($this->snapshot(status: RequestStatus::InProgress));
        $renderer = new RecordingOpinionRenderer('%PDF opinion');

        $this->expectException(OpinionDenied::class);
        try {
            (new PublishOpinion($gateway, $renderer))->execute(new PublishOpinionCommand(41, 7, str_repeat('x', 10), 3));
        } finally {
            self::assertNull($renderer->body);
            self::assertNull($gateway->persistedPdf);
        }
    }

    public function testStaleVersionIsRejectedBeforeRenderingOrPersistence(): void
    {
        $gateway = new InMemoryPublishOpinionGateway($this->snapshot());
        $renderer = new RecordingOpinionRenderer('%PDF opinion');

        $this->expectException(ConcurrentRequestModification::class);
        try {
            (new PublishOpinion($gateway, $renderer))->execute(new PublishOpinionCommand(41, 7, str_repeat('x', 10), 2));
        } finally {
            self::assertNull($renderer->body);
            self::assertNull($gateway->persistedPdf);
        }
    }

    private function snapshot(
        RequestStatus $status = RequestStatus::OpinionPreparation,
        bool $isCurrentExpert = true,
    ): PublishOpinionSnapshot {
        return new PublishOpinionSnapshot(
            123,
            $status,
            3,
            'Лифт',
            'Завод',
            'Поставщик',
            'Эксперт',
            'Инженер',
            true,
            $isCurrentExpert,
        );
    }
}
