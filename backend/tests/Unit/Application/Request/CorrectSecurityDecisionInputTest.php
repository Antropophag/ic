<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\CorrectSecurityDecisionInput;
use PHPUnit\Framework\TestCase;

final class CorrectSecurityDecisionInputTest extends TestCase
{
    public function testBothDecisionsRequireReasonTicketAndVersion(): void
    {
        foreach (['approve', 'decline'] as $decision) {
            $input = new CorrectSecurityDecisionInput();
            $input->load(['decision' => $decision, 'reason' => ' Исправлена ошибка ', 'ticketReference' => ' IT-360 ', 'lockVersion' => 2], '');
            self::assertTrue($input->validate());
            self::assertSame('Исправлена ошибка', $input->reason);
            self::assertSame('IT-360', $input->ticketReference);
        }
    }

    public function testInvalidInputIsRejected(): void
    {
        $valid = ['decision' => 'approve', 'reason' => 'Ошибка', 'ticketReference' => 'IT-360', 'lockVersion' => 2];
        foreach (
            [
            'decision' => [null, '', 'return', [], true],
            'reason' => [null, '', '   ', [], true, str_repeat('я', 5001)],
            'ticketReference' => [null, '', '   ', [], true, str_repeat('я', 1001)],
            'lockVersion' => [null, 0, -1, [], 'bad'],
            ] as $field => $values
        ) {
            foreach ($values as $value) {
                $input = new CorrectSecurityDecisionInput();
                $input->load(array_replace($valid, [$field => $value]), '');
                self::assertFalse($input->validate(), $field);
                self::assertArrayHasKey($field, $input->errors);
            }
        }
    }
}
