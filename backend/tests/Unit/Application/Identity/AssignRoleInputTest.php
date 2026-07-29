<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Identity;

use App\Application\Identity\AssignRoleInput;
use PHPUnit\Framework\TestCase;

final class AssignRoleInputTest extends TestCase
{
    public function testAcceptsAPositiveRoleId(): void
    {
        $input = new AssignRoleInput();
        $input->setAttributes(['roleId' => 4]);

        self::assertTrue($input->validate());
    }

    public function testRejectsANonPositiveRoleId(): void
    {
        $input = new AssignRoleInput();
        $input->setAttributes(['roleId' => 0]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('roleId', $input->errors);
    }

    public function testRejectsANonNumericRoleIdAsA422RatherThanCrashing(): void
    {
        // roleId — mixed, не ?int: непохожий на число JSON-тип (объект/массив)
        // не должен ронять Model::load() с TypeError до того, как отработает
        // валидатор 'integer' и вернёт контролируемую ошибку 422.
        $input = new AssignRoleInput();
        $input->setAttributes(['roleId' => ['not', 'a', 'number']]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('roleId', $input->errors);
    }
}
