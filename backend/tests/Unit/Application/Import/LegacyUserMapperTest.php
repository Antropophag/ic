<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import;

use App\Application\Import\LegacyUserMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class LegacyUserMapperTest extends TestCase
{
    public function testMapsEmailNamePositionAndActiveFlag(): void
    {
        $user = (new LegacyUserMapper())->map([
            'ID' => '77',
            'EMAIL' => ' Ivanov.I@example.test ',
            'NAME' => 'Иван',
            'LAST_NAME' => 'Иванов',
            'SECOND_NAME' => 'Иванович',
            'WORK_POSITION' => 'Инженер',
            'ACTIVE' => 'Y',
        ], '77');

        self::assertSame('ivanov.i', $user->adLogin);
        self::assertSame('ivanov.i@example.test', $user->email);
        self::assertSame('Иванов Иван Иванович', $user->displayName);
        self::assertSame('Инженер', $user->position);
        self::assertTrue($user->active);
    }

    /** @param array<string, mixed> $user */
    #[DataProvider('invalidUsers')]
    public function testRejectsIncompleteOrUnsafeIdentity(array $user): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new LegacyUserMapper())->map($user, '77');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUsers(): iterable
    {
        $valid = ['ID' => '77', 'EMAIL' => 'user@example.test', 'NAME' => 'Иван', 'LAST_NAME' => 'Иванов', 'ACTIVE' => 'Y'];
        yield 'wrong ID' => [[...$valid, 'ID' => '78']];
        yield 'missing email' => [[...$valid, 'EMAIL' => '']];
        yield 'unsafe login' => [[...$valid, 'EMAIL' => 'иван@example.test']];
        yield 'missing first name' => [[...$valid, 'NAME' => '']];
        yield 'missing last name' => [[...$valid, 'LAST_NAME' => '']];
        yield 'invalid active flag' => [[...$valid, 'ACTIVE' => '1']];
        yield 'numeric first name' => [[...$valid, 'NAME' => 123]];
        yield 'boolean ID' => [[...$valid, 'ID' => true]];
    }
}
