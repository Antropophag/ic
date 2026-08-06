<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\AssignExecutorInput;
use PHPUnit\Framework\TestCase;

final class AssignExecutorInputTest extends TestCase
{
    public function testAcceptsExecutorAndLockVersion(): void
    {
        $input = new AssignExecutorInput();
        $input->setAttributes(['executorId' => 2, 'lockVersion' => 3]);

        self::assertTrue($input->validate());
    }

    public function testRequiresPositiveIdentifiers(): void
    {
        $input = new AssignExecutorInput();
        $input->setAttributes(['executorId' => 0, 'lockVersion' => 0]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('executorId', $input->errors);
        self::assertArrayHasKey('lockVersion', $input->errors);
    }
}
