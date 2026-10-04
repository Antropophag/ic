<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ListRequestsInput;
use PHPUnit\Framework\TestCase;

final class ListRequestsInputTest extends TestCase
{
    public function testAcceptsKnownAttentionQueue(): void
    {
        $input = new ListRequestsInput(['attention' => 'assign_executor']);

        self::assertTrue($input->validate());
    }

    public function testRejectsUnknownAttentionQueue(): void
    {
        $input = new ListRequestsInput(['attention' => 'someone_else']);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('attention', $input->errors);
    }

    public function testAcceptsAndNormalizesMultipleStatuses(): void
    {
        $input = new ListRequestsInput(['status' => 'completed,rejected,completed']);

        self::assertTrue($input->validate());
        self::assertSame(['completed', 'rejected'], $input->statuses());
    }

    /**
     * @param list<string> $expected
     * @dataProvider validStatusProvider
     */
    public function testAcceptsExplicitStatusContract(mixed $status, array $expected): void
    {
        $input = new ListRequestsInput(['status' => $status]);

        self::assertTrue($input->validate());
        self::assertSame($expected, $input->statuses());
    }

    /** @return iterable<string, array{mixed, list<string>}> */
    public static function validStatusProvider(): iterable
    {
        yield 'missing' => [null, []];
        yield 'empty' => ['', []];
        yield 'single' => ['completed', ['completed']];
        yield 'duplicates' => ['completed,rejected,completed', ['completed', 'rejected']];
    }

    public function testRejectsAListContainingAnUnknownStatus(): void
    {
        $input = new ListRequestsInput(['status' => 'completed,unknown']);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('status', $input->errors);
    }

    /** @dataProvider invalidStatusProvider */
    public function testRejectsAmbiguousOrOversizedStatusLists(mixed $status): void
    {
        $input = new ListRequestsInput(['status' => $status]);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('status', $input->errors);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidStatusProvider(): iterable
    {
        yield 'trailing empty' => ['completed,'];
        yield 'leading empty' => [',completed'];
        yield 'middle empty' => ['completed,,rejected'];
        yield 'only separators' => [',,,'];
        yield 'leading whitespace' => [' completed'];
        yield 'trailing whitespace' => ['completed '];
        yield 'too many raw values' => [implode(',', array_fill(0, 9, 'completed'))];
        yield 'wrong query shape' => [['completed', 'rejected']];
    }
}
