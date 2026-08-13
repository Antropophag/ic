<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Request;

use App\Http\Request\SetColorRequest;
use PHPUnit\Framework\TestCase;

final class SetColorRequestTest extends TestCase
{
    public function testAcceptsAKnownColorAndLockVersion(): void
    {
        $input = new SetColorRequest();
        $input->setAttributes(['color' => 'red', 'lockVersion' => 3]);

        self::assertTrue($input->validate());
    }

    public function testRejectsAnUnknownColor(): void
    {
        $input = new SetColorRequest();
        $input->setAttributes(['color' => 'pink', 'lockVersion' => 1]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('color', $input->errors);
    }

    public function testRejectsANonStringColorEvenIfLooselyInRange(): void
    {
        $input = new SetColorRequest();
        $input->setAttributes(['color' => true, 'lockVersion' => 1]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('color', $input->errors);
    }

    public function testRequiresBothFields(): void
    {
        $input = new SetColorRequest();
        $input->setAttributes([]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('color', $input->errors);
        self::assertArrayHasKey('lockVersion', $input->errors);
    }

    public function testRejectsANonPositiveLockVersion(): void
    {
        $input = new SetColorRequest();
        $input->setAttributes(['color' => 'white', 'lockVersion' => 0]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('lockVersion', $input->errors);
    }
}
