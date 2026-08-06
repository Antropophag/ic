<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Identity;

use App\Application\Identity\CreateUserInput;
use PHPUnit\Framework\TestCase;

final class CreateUserInputTest extends TestCase
{
    public function testAcceptsAnAdLoginWithoutADisplayName(): void
    {
        // Отображаемое имя необязательно: AdminController::actionCreateUser()
        // подставляет ad_login по умолчанию, реальное имя придёт из AD при
        // первом входе (issue #102).
        $input = new CreateUserInput();
        $input->setAttributes(['adLogin' => 'ivanov']);

        self::assertTrue($input->validate());
    }

    public function testAcceptsAnExplicitDisplayName(): void
    {
        $input = new CreateUserInput();
        $input->setAttributes(['adLogin' => 'ivanov', 'displayName' => 'Иван Иванов']);

        self::assertTrue($input->validate());
    }

    public function testRejectsAMissingAdLogin(): void
    {
        $input = new CreateUserInput();
        $input->setAttributes(['displayName' => 'Иван Иванов']);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('adLogin', $input->errors);
    }

    public function testRejectsAnAdLoginWithDisallowedCharacters(): void
    {
        // UPN-подобный ввод ("ivanov@shlz.ru") или пробелы недопустимы —
        // NativeLdapClient сам добавит домен через "{login}@{domain}".
        $input = new CreateUserInput();
        $input->setAttributes(['adLogin' => 'ivanov@shlz.ru']);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('adLogin', $input->errors);
    }
}
