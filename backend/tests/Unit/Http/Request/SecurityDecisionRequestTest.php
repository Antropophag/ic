<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Request;

use App\Http\Request\SecurityDecisionRequest;
use PHPUnit\Framework\TestCase;

final class SecurityDecisionRequestTest extends TestCase
{
    public function testValidDecisions(): void
    {
        foreach ([['approve', null], ['return', 'Нужны повторные испытания']] as [$decision, $reason]) {
            $input = new SecurityDecisionRequest();
            $input->load(['decision' => $decision, 'reason' => $reason, 'lockVersion' => 6], '');
            self::assertTrue($input->validate());
        }
    }

    public function testCommandPreservesNullReasonAndCastsVersion(): void
    {
        $input = new SecurityDecisionRequest();
        $input->load(['decision' => 'approve', 'reason' => null, 'lockVersion' => '6'], '');
        self::assertTrue($input->validate());

        $command = $input->toCommand(10, 20);
        self::assertNull($command->reason);
        self::assertSame(6, $command->expectedLockVersion);
    }

    public function testReturnRequiresReasonAndVersion(): void
    {
        $input = new SecurityDecisionRequest();
        $input->load(['decision' => 'return', 'reason' => '   ', 'lockVersion' => 0], '');
        self::assertFalse($input->validate());
        self::assertArrayHasKey('reason', $input->errors);
        self::assertArrayHasKey('lockVersion', $input->errors);
    }

    public function testStructuredReasonIsRejectedWithoutConversion(): void
    {
        foreach ([['не строка'], (object) ['reason' => 'не строка']] as $reason) {
            $input = new SecurityDecisionRequest();
            $input->load(['decision' => 'return', 'reason' => $reason, 'lockVersion' => 6], '');

            self::assertFalse($input->validate());
            self::assertArrayHasKey('reason', $input->errors);
            self::assertSame($reason, $input->reason);
        }
    }

    public function testStringReasonIsTrimmed(): void
    {
        $input = new SecurityDecisionRequest();
        $input->load(['decision' => 'return', 'reason' => '  Уточнить вывод  ', 'lockVersion' => 6], '');

        self::assertTrue($input->validate());
        self::assertSame('Уточнить вывод', $input->reason);
    }
}
