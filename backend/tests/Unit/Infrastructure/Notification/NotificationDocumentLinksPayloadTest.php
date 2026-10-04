<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\InvalidNotificationPayload;
use App\Infrastructure\Notification\NotificationDocumentLinksPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationDocumentLinksPayloadTest extends TestCase
{
    public function testAcceptsJsonAndIgnoresUnknownFields(): void
    {
        self::assertSame(
            [['label' => 'Протокол', 'documentVersionId' => 42]],
            NotificationDocumentLinksPayload::parse(json_encode([
                'documentLinks' => [[
                    'label' => 'Протокол',
                    'documentVersionId' => 42,
                    'futureMetadata' => 'preserved compatibility',
                ]],
                'futureRootField' => true,
            ], JSON_THROW_ON_ERROR)),
        );
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedPayload(mixed $payload): void
    {
        $this->expectException(InvalidNotificationPayload::class);
        NotificationDocumentLinksPayload::parse($payload);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'non-array root' => [new \stdClass()];
        yield 'missing collection' => [[]];
        yield 'non-array collection' => [['documentLinks' => 'invalid']];
        yield 'scalar item' => [['documentLinks' => ['invalid']]];
        yield 'missing label' => [['documentLinks' => [['documentVersionId' => 1]]]];
        yield 'non-string label' => [['documentLinks' => [['label' => [], 'documentVersionId' => 1]]]];
        yield 'blank label' => [['documentLinks' => [['label' => '  ', 'documentVersionId' => 1]]]];
        yield 'missing version id' => [['documentLinks' => [['label' => 'Протокол']]]];
        yield 'string version id' => [['documentLinks' => [['label' => 'Протокол', 'documentVersionId' => '42']]]];
        yield 'zero version id' => [['documentLinks' => [['label' => 'Протокол', 'documentVersionId' => 0]]]];
        yield 'negative version id' => [['documentLinks' => [['label' => 'Протокол', 'documentVersionId' => -1]]]];
    }
}
