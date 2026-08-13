<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Request;

use App\Http\Request\ChangeDepartmentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChangeDepartmentRequestTest extends TestCase
{
    public function testTrimsValidDepartment(): void
    {
        $input = new ChangeDepartmentRequest();
        $input->department = '  Подразделение C  ';
        $input->lockVersion = 3;

        self::assertTrue($input->validate());
        self::assertSame('Подразделение C', $input->department);
    }

    public function testRejectsWhitespaceOnlyDepartment(): void
    {
        $input = new ChangeDepartmentRequest();
        $input->department = '   ';
        $input->lockVersion = 3;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('department', $input->errors);
    }

    #[DataProvider('nonStringDepartments')]
    public function testRejectsNonStringDepartmentWithoutTypeError(mixed $department): void
    {
        $input = new ChangeDepartmentRequest();
        $input->department = $department;
        $input->lockVersion = 3;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('department', $input->errors);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonStringDepartments(): iterable
    {
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'integer' => [1];
        yield 'array' => [['Подразделение']];
        yield 'object' => [(object) ['name' => 'Подразделение']];
    }
}
