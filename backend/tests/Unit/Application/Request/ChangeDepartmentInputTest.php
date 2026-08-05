<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ChangeDepartmentInput;
use PHPUnit\Framework\TestCase;

final class ChangeDepartmentInputTest extends TestCase
{
    public function testTrimsValidDepartment(): void
    {
        $input = new ChangeDepartmentInput();
        $input->department = '  Подразделение C  ';
        $input->lockVersion = 3;

        self::assertTrue($input->validate());
        self::assertSame('Подразделение C', $input->department);
    }

    public function testRejectsWhitespaceOnlyDepartment(): void
    {
        $input = new ChangeDepartmentInput();
        $input->department = '   ';
        $input->lockVersion = 3;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('department', $input->errors);
    }
}
