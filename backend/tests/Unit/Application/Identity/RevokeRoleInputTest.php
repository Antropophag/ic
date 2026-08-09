<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Identity;

use App\Application\Identity\RevokeRoleInput;
use PHPUnit\Framework\TestCase;

final class RevokeRoleInputTest extends TestCase
{
    public function testAcceptsAndTrimsReason(): void
    {
        $input = new RevokeRoleInput();
        $input->reason = '  Сотрудник сменил обязанности  ';

        self::assertTrue($input->validate());
        self::assertSame('Сотрудник сменил обязанности', $input->reason);
    }

    public function testRejectsBlankReason(): void
    {
        $input = new RevokeRoleInput();
        $input->reason = '   ';

        self::assertFalse($input->validate());
        self::assertArrayHasKey('reason', $input->errors);
    }
}
