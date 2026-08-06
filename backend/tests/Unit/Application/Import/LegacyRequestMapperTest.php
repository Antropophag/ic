<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import;

use App\Application\Import\LegacyRequestMapper;
use App\Application\Import\LegacyUserData;
use App\Domain\Request\RequestStatus;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class LegacyRequestMapperTest extends TestCase
{
    public function testMapsWhitelistedLegacyFields(): void
    {
        $details = [
            'nameType' => 'Редуктор',
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'countTestItems' => '2 шт',
            'testMethod' => 'Методика',
            'status' => 'Выполнено',
            'dateCreate' => '2025-02-03T10:20:30+03:00',
            'creator' => ['ID' => '77', 'NAME' => 'Иван', 'LAST_NAME' => 'Иванов', 'PERSONAL_PHONE' => 'secret'],
            'department' => ['ID' => '3', 'NAME' => 'Лаборатория'],
            'supportingDocFiles' => [['id' => 1]],
            'reportFiles' => [['id' => 2], ['id' => 3]],
        ];

        $request = $this->mapper()->map([
            'ID' => '42',
            'DETAIL_TEXT' => json_encode($details, JSON_THROW_ON_ERROR),
        ], 114);

        self::assertSame('bitrix24:114:42', $request->legacyId);
        self::assertSame(42, $request->number);
        self::assertSame(RequestStatus::Completed, $request->status);
        self::assertSame(2, $request->sampleQuantity);
        self::assertSame('Иванов Иван', $request->creator->displayName);
        self::assertSame(1, $request->supportingDocumentCount);
        self::assertSame(2, $request->reportCount);
        self::assertObjectNotHasProperty('personalPhone', $request);
    }

    public function testRejectsUnknownStatus(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map([
            'ID' => '42',
            'DETAIL_TEXT' => json_encode([
                'nameType' => 'Редуктор',
                'manufacturer' => 'Завод',
                'supplier' => 'Поставщик',
                'countTestItems' => '1',
                'status' => 'Новый неизвестный статус',
                'dateCreate' => '2025-02-03T10:20:30+03:00',
                'creator' => ['ID' => '77'],
            ], JSON_THROW_ON_ERROR),
        ], 114);
    }

    public function testMapsStrictDateOnlyValueAtUtcMidnight(): void
    {
        $request = $this->mapper()->map($this->element(['dateCreate' => '2025-02-03']), 114);

        self::assertSame('2025-02-03T00:00:00+00:00', $request->createdAt->format(DateTimeInterface::ATOM));
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDate(string $date): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element(['dateCreate' => $date]), 114);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        yield 'невозможный день' => ['2025-02-30T10:20:30+03:00'];
        yield 'невозможный день без времени' => ['2025-02-30'];
        yield 'неподдерживаемый формат' => ['2025-02-03 10:20:30'];
    }

    public function testRejectsNonNumericCreatorId(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element([
            'creator' => ['ID' => '../admin'],
        ]), 114);
    }

    public function testRejectsNonStringRequestFields(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element(['nameType' => 123]), 114);
    }

    public function testRejectsNonStringCreatorId(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element(['creator' => ['ID' => true]]), 114);
    }

    public function testRejectsStringLongerThanDatabaseColumn(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element([
            'nameType' => str_repeat('Я', 501),
        ]), 114);
    }

    public function testRejectsQuantityOutsideUnsignedDatabaseInteger(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element([
            'countTestItems' => '4294967296',
        ]), 114);
    }

    #[DataProvider('knownStatuses')]
    public function testMapsEveryKnownStatus(string $legacyStatus, RequestStatus $expected): void
    {
        $request = $this->mapper()->map($this->element([
            'status' => $legacyStatus,
            'countTestItems' => '3.000',
        ]), 114);

        self::assertSame($expected, $request->status);
        self::assertSame(3, $request->sampleQuantity);
        self::assertSame(0, $request->reportCount);
    }

    /** @return iterable<string, array{string, RequestStatus}> */
    public static function knownStatuses(): iterable
    {
        yield 'зарегистрирована' => ['Заявка зарегистрирована', RequestStatus::Registered];
        yield 'в работе' => ['Заявка в работе', RequestStatus::InProgress];
        yield 'фактическое значение в работе' => ['В работе', RequestStatus::InProgress];
        yield 'приостановлена' => ['Работы приостановлены', RequestStatus::Suspended];
        yield 'фактическое значение приостановлена' => ['Приостановлено', RequestStatus::Suspended];
        yield 'заключение' => ['Подготовка заключения', RequestStatus::OpinionPreparation];
        yield 'контроль СБ' => ['Контроль СБ', RequestStatus::SecurityReview];
        yield 'выполнена' => ['Заявка выполнена', RequestStatus::Completed];
        yield 'отказана' => ['Отказано', RequestStatus::Rejected];
        yield 'фактическое значение отказана' => ['В проведении испытаний отказано', RequestStatus::Rejected];
        yield 'отозвана' => ['Заявка отозвана', RequestStatus::Withdrawn];
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function element(array $overrides = []): array
    {
        return [
            'ID' => '42',
            'DETAIL_TEXT' => json_encode([
                'nameType' => 'Редуктор',
                'manufacturer' => 'Завод',
                'supplier' => 'Поставщик',
                'countTestItems' => '1',
                'testMethod' => '',
                'status' => 'Выполнено',
                'dateCreate' => '2025-02-03T10:20:30+03:00',
                'creator' => ['ID' => '77'],
                ...$overrides,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function mapper(): LegacyRequestMapper
    {
        return new LegacyRequestMapper([
            '77' => new LegacyUserData('77', 'ivanov', 'Иванов Иван', 'ivanov@example.test', null, true),
        ]);
    }
}
