<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Request;

use App\Infrastructure\Request\RequestStatusScope;
use PHPUnit\Framework\TestCase;

final class RequestStatusScopeTest extends TestCase
{
    public function testUsesUniqueBoundParametersForMultipleStatuses(): void
    {
        $where = [];
        $params = [];

        (new RequestStatusScope())->apply($where, $params, ['completed', 'rejected']);

        self::assertSame(['r.status IN (:filter_status_0, :filter_status_1)'], $where);
        self::assertSame([
            ':filter_status_0' => 'completed',
            ':filter_status_1' => 'rejected',
        ], $params);
    }

    public function testSupportsAnInternalSingleStatusWithoutInterpolation(): void
    {
        $where = [];
        $params = [];

        (new RequestStatusScope())->apply($where, $params, 'completed');

        self::assertSame(['r.status IN (:filter_status_0)'], $where);
        self::assertSame([':filter_status_0' => 'completed'], $params);
    }

    /**
     * @param list<string>|null $status
     * @dataProvider emptyStatusProvider
     */
    public function testDoesNotAddAConditionForAnEmptyFilter(string|array|null $status): void
    {
        $where = ['r.is_archived = 0'];
        $params = [':existing' => 1];

        (new RequestStatusScope())->apply($where, $params, $status);

        self::assertSame(['r.is_archived = 0'], $where);
        self::assertSame([':existing' => 1], $params);
    }

    /** @return iterable<string, array{list<string>|null}> */
    public static function emptyStatusProvider(): iterable
    {
        yield 'null' => [null];
        yield 'list' => [[]];
    }
}
