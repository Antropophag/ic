<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\OpinionDenied;
use App\Domain\Request\OpinionPolicy;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpinionPolicyTest extends TestCase
{
    public function testCurrentActiveExpertCanPublish(): void
    {
        $this->expectNotToPerformAssertions();
        (new OpinionPolicy())->assertCanPublish(RequestStatus::OpinionPreparation, true, true);
    }

    #[DataProvider('deniedCases')]
    public function testDenied(string $rule, RequestStatus $status, bool $active, bool $current): void
    {
        $this->expectException(OpinionDenied::class);
        $this->expectExceptionMessage($rule);
        (new OpinionPolicy())->assertCanPublish($status, $active, $current);
    }

    /** @return iterable<string, array{string, RequestStatus, bool, bool}> */
    public static function deniedCases(): iterable
    {
        yield 'неактивный' => ['AUTH-003', RequestStatus::OpinionPreparation, false, true];
        yield 'не тот этап' => ['DOC-007', RequestStatus::InProgress, true, true];
        yield 'не назначен' => ['DOC-005', RequestStatus::OpinionPreparation, true, false];
    }
}
