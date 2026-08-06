<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\SetColorInput;
use PHPUnit\Framework\TestCase;

final class SetColorInputTest extends TestCase
{
    public function testAcceptsAKnownColorAndLockVersion(): void
    {
        $input = new SetColorInput();
        $input->setAttributes(['color' => 'red', 'lockVersion' => 3]);

        self::assertTrue($input->validate());
    }

    public function testRejectsAnUnknownColor(): void
    {
        $input = new SetColorInput();
        $input->setAttributes(['color' => 'pink', 'lockVersion' => 1]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('color', $input->errors);
    }

    public function testRejectsANonStringColorEvenIfLooselyInRange(): void
    {
        $input = new SetColorInput();
        $input->setAttributes(['color' => true, 'lockVersion' => 1]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('color', $input->errors);
    }

    public function testRequiresBothFields(): void
    {
        $input = new SetColorInput();
        $input->setAttributes([]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('color', $input->errors);
        self::assertArrayHasKey('lockVersion', $input->errors);
    }

    public function testRejectsANonPositiveLockVersion(): void
    {
        $input = new SetColorInput();
        $input->setAttributes(['color' => 'white', 'lockVersion' => 0]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('lockVersion', $input->errors);
    }
}
