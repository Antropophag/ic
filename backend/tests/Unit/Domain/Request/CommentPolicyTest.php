<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\CommentDenied;
use App\Domain\Request\CommentPolicy;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommentPolicyTest extends TestCase
{
    #[DataProvider('openStatuses')]
    public function testAllowsCommentBeforeSecurityDecision(RequestStatus $status): void
    {
        (new CommentPolicy())->assertCanAdd($status);
        self::addToAssertionCount(1);
    }

    /** @return iterable<array{RequestStatus}> */
    public static function openStatuses(): iterable
    {
        yield [RequestStatus::Registered];
        yield [RequestStatus::InProgress];
        yield [RequestStatus::Suspended];
        yield [RequestStatus::OpinionPreparation];
        yield [RequestStatus::SecurityReview];
    }

    public function testRejectsCommentAfterCompletion(): void
    {
        $this->expectException(CommentDenied::class);
        (new CommentPolicy())->assertCanAdd(RequestStatus::Completed);
    }
}
