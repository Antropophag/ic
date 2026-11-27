<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\CloseRequestInput;
use PHPUnit\Framework\TestCase;

final class CloseRequestInputTest extends TestCase
{
    public function testDefaultsRequireBothReviews(): void
    {
        $input = new CloseRequestInput();
        $input->load(['lockVersion' => 1], '');
        self::assertTrue($input->validate());
        self::assertTrue($input->expertiseRequired);
        self::assertTrue($input->securityRequired);
    }

    public function testAcceptsThreeCombinationsAndRejectsSecurityAlone(): void
    {
        foreach ([[true, true, true], [true, false, true], [false, false, true], [false, true, false]] as [$expertise, $security, $valid]) {
            $input = new CloseRequestInput();
            $input->load(['lockVersion' => 1, 'expertiseRequired' => $expertise, 'securityRequired' => $security], '');
            self::assertSame($valid, $input->validate());
        }
    }

    public function testRejectsNonBooleanValuesAndMissingVersion(): void
    {
        foreach (['expertiseRequired', 'securityRequired'] as $field) {
            foreach ([null, '', 0, 1, 'false', [], (object) []] as $value) {
                $input = new CloseRequestInput();
                $input->load(['lockVersion' => 1, $field => $value], '');
                self::assertFalse($input->validate());
                self::assertArrayHasKey($field, $input->errors);
            }
        }
        self::assertFalse((new CloseRequestInput())->validate());
    }
}
