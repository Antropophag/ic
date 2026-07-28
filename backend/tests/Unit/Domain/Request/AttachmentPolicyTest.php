<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\AttachmentDenied;
use App\Domain\Request\AttachmentPolicy;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachmentPolicyTest extends TestCase
{
    #[DataProvider('openStatuses')]
    public function testAllowsUploadWhileDiscussionIsOpen(RequestStatus $status): void
    {
        (new AttachmentPolicy())->assertCanUpload($status);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{RequestStatus}> */
    public static function openStatuses(): iterable
    {
        foreach (
            [RequestStatus::Registered, RequestStatus::InProgress, RequestStatus::Suspended,
            RequestStatus::OpinionPreparation, RequestStatus::SecurityReview] as $status
        ) {
            yield $status->value => [$status];
        }
    }

    public function testRejectsUploadAfterCompletion(): void
    {
        $this->expectException(AttachmentDenied::class);
        (new AttachmentPolicy())->assertCanUpload(RequestStatus::Completed);
    }

    public function testAcceptsMatchingExtensionMimeAndSize(): void
    {
        (new AttachmentPolicy())->assertValidFile('Протокол.pdf', 'application/pdf', 1024);
        self::addToAssertionCount(1);
    }

    #[DataProvider('invalidFiles')]
    public function testRejectsInvalidFile(string $name, string $mime, int $size): void
    {
        $this->expectException(AttachmentDenied::class);
        (new AttachmentPolicy())->assertValidFile($name, $mime, $size);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function invalidFiles(): iterable
    {
        yield 'empty' => ['', 'application/pdf', 1];
        yield 'empty content' => ['test.pdf', 'application/pdf', 0];
        yield 'too large' => ['test.pdf', 'application/pdf', AttachmentPolicy::MAX_SIZE_BYTES + 1];
        yield 'extension' => ['test.php', 'application/pdf', 1];
        yield 'mime mismatch' => ['test.pdf', 'text/html', 1];
        yield 'path-like name' => [str_repeat('a', 256) . '.pdf', 'application/pdf', 1];
    }
}
