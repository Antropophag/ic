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
        self::assertSame('2 шт', $request->legacySampleQuantityRaw);
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

    public function testMapsLegacyCommentsAndTheirAuthors(): void
    {
        $request = $this->mapper()->map($this->element([
            'commentsIC' => [[
                'id' => '900',
                'text' => 'Комментарий ИЦ',
                'dateCreated' => '03.02.2025 в 13:20',
                'creator' => [
                    'ID' => '88', 'EMAIL' => 'petrov@example.test', 'NAME' => 'Пётр',
                    'LAST_NAME' => 'Петров', 'SECOND_NAME' => '', 'ACTIVE' => true, 'WORK_POSITION' => '',
                ],
                'files' => [['id' => 7]],
            ]],
        ]), 114);

        self::assertCount(1, $request->comments);
        self::assertSame('bitrix24:114:42:comment:commentsIC:900', $request->comments[0]->legacyId);
        self::assertSame('Петров Пётр', $request->comments[0]->creator->displayName);
        self::assertSame('2025-02-03T10:20:00+00:00', $request->comments[0]->createdAt->format(DateTimeInterface::ATOM));
        self::assertSame(1, $request->comments[0]->fileCount);
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

    public function testRejectsLegacyRequestIdOutsidePositiveIntegerRange(): void
    {
        $element = $this->element();
        $element['ID'] = '999999999999999999999999999999';

        $this->expectExceptionMessage('Legacy request ID must fit a positive integer.');
        $this->mapper()->map($element, 114);
    }

    public function testRejectsNonNumericStructuredCreatorFallback(): void
    {
        $element = $this->element(['creator' => null]);
        $element['CREATED_BY'] = 'system';

        $this->expectExceptionMessage('Legacy creator ID must be numeric.');
        $this->mapper()->map($element, 114);
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

    public function testRejectsLegacyProductNameLongerThanLegacyLimit(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element([
            'nameType' => str_repeat('Я', 2001),
        ]), 114);
    }

    public function testPreservesLongLegacyProductName(): void
    {
        foreach ([501, 1728] as $length) {
            $name = str_repeat('Я', $length);
            $request = $this->mapper()->map($this->element(['nameType' => $name]), 114);

            self::assertSame($name, $request->productName);
            self::assertSame($length, preg_match_all('/./us', $request->productName));
        }
    }

    public function testFallsBackToStructuredCreatedBy(): void
    {
        $element = $this->element(['creator' => null]);
        $element['CREATED_BY'] = '77';

        $request = $this->mapper()->map($element, 114);

        self::assertSame('77', $request->creator->bitrixId);
    }

    public function testPrefersDetailsCreatorOverCreatedBy(): void
    {
        $element = $this->element();
        $element['CREATED_BY'] = '88';

        $request = $this->mapper()->map($element, 114);

        self::assertSame('77', $request->creator->bitrixId);
    }

    public function testRejectsRequestWithoutEitherCreatorId(): void
    {
        $this->expectExceptionMessage('Legacy creator ID is required.');
        $this->mapper()->map($this->element(['creator' => null]), 114);
    }

    public function testIgnoresOnlyEmptyTechnicalCommentItem(): void
    {
        $request = $this->mapper()->map($this->element([
            'commentsInitiator' => [['files' => []]],
        ]), 114);

        self::assertSame([], $request->comments);
    }

    public function testDoesNotIgnoreIncompleteRealComment(): void
    {
        $this->expectExceptionMessage('Legacy field ID is required.');
        $this->mapper()->map($this->element([
            'commentsInitiator' => [['text' => 'Содержательный комментарий', 'files' => []]],
        ]), 114);
    }

    public function testUsesCounterpartyOverrideWithoutClearingOtherSourceField(): void
    {
        $request = $this->mapper(
            counterpartyOverrides: ['bitrix24:114:42' => ['manufacturer' => 'АО ЩЛЗ (кооперация)']],
        )->map($this->element(['manufacturer' => ' ']), 114);

        self::assertSame('АО ЩЛЗ (кооперация)', $request->manufacturer);
        self::assertSame('Поставщик', $request->supplier);
    }

    public function testAppliesApprovedCounterpartyDecisionFor18312(): void
    {
        $request = $this->mapCounterpartyDecision(
            '18312',
            ['manufacturer' => ' ', 'supplier' => ' '],
            ['manufacturer' => 'АО ЩЛЗ (кооперация)', 'supplier' => 'Поставщик не указан'],
        );

        self::assertSame('АО ЩЛЗ (кооперация)', $request->manufacturer);
        self::assertSame('Поставщик не указан', $request->supplier);
    }

    public function testAppliesApprovedCounterpartyDecisionFor18314(): void
    {
        $request = $this->mapCounterpartyDecision(
            '18314',
            ['manufacturer' => ' ', 'supplier' => ' '],
            ['manufacturer' => 'Производитель не указан', 'supplier' => 'Поставщик не указан'],
        );

        self::assertSame('Производитель не указан', $request->manufacturer);
        self::assertSame('Поставщик не указан', $request->supplier);
    }

    #[DataProvider('legacyRequestsWithKnownManufacturer')]
    public function testSupplierDecisionPreservesKnownManufacturer(string $legacyId, string $manufacturer): void
    {
        $request = $this->mapCounterpartyDecision(
            $legacyId,
            ['manufacturer' => $manufacturer, 'supplier' => ' '],
            ['supplier' => 'Поставщик не указан'],
        );

        self::assertSame($manufacturer, $request->manufacturer);
        self::assertSame('Поставщик не указан', $request->supplier);
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyRequestsWithKnownManufacturer(): iterable
    {
        yield '22610' => ['22610', 'ООО «Известный производитель»'];
        yield '29840' => ['29840', 'АО «Другой производитель»'];
    }

    public function testDoesNotInventSupplierForArbitraryLegacyRequest(): void
    {
        $this->expectExceptionMessage('Legacy field supplier is required.');
        $this->mapper()->map($this->element(['supplier' => ' ']), 114);
    }

    public function testDoesNotInventManufacturerForArbitraryLegacyRequest(): void
    {
        $this->expectExceptionMessage('Legacy field manufacturer is required.');
        $this->mapper()->map($this->element(['manufacturer' => ' ']), 114);
    }

    public function testRejectsQuantityOutsideUnsignedDatabaseInteger(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->mapper()->map($this->element([
            'countTestItems' => '4294967296',
        ]), 114);
    }

    #[DataProvider('unambiguousQuantities')]
    public function testNormalizesOnlyUnambiguousQuantity(string $raw, int $expected): void
    {
        $request = $this->mapper()->map($this->element(['countTestItems' => $raw]), 114);

        self::assertSame($expected, $request->sampleQuantity);
        self::assertSame($raw, $request->legacySampleQuantityRaw);
    }

    /** @return iterable<string, array{string, int}> */
    public static function unambiguousQuantities(): iterable
    {
        yield 'plain integer' => ['5', 5];
        yield 'unit with space' => ['5 шт.', 5];
        yield 'unit without space' => ['5шт', 5];
        yield 'unit without space and dot' => ['5шт.', 5];
    }

    #[DataProvider('ambiguousQuantities')]
    public function testPreservesAmbiguousQuantityWithoutInventingNumber(string $raw): void
    {
        $request = $this->mapper()->map($this->element(['countTestItems' => $raw]), 114);

        self::assertNull($request->sampleQuantity);
        self::assertSame($raw, $request->legacySampleQuantityRaw);
    }

    public function testUsesControlledOverrideAndPreservesRawQuantity(): void
    {
        $raw = 'Количество номенклатурных позиций - 2 шт. По 3 шт. каждой номенклатурной позиции.';
        $request = $this->mapper(['bitrix24:114:42' => 6])->map(
            $this->element(['countTestItems' => $raw]),
            114,
        );

        self::assertSame(6, $request->sampleQuantity);
        self::assertSame($raw, $request->legacySampleQuantityRaw);
    }

    public function testDoesNotApplyNxMToUnknownLegacyId(): void
    {
        $raw = 'Количество номенклатурных позиций - 2 шт. По 3 шт. каждой номенклатурной позиции.';
        $request = $this->mapper(['bitrix24:114:99' => 6])->map(
            $this->element(['countTestItems' => $raw]),
            114,
        );

        self::assertNull($request->sampleQuantity);
        self::assertSame($raw, $request->legacySampleQuantityRaw);
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousQuantities(): iterable
    {
        yield 'per kind' => ['по 1 шт. каждого вида'];
        yield 'attachment reference' => ['согласно перечню во вложении'];
        yield 'dash' => ['-'];
        yield 'approximation' => ['несколько штук'];
        yield 'set' => ['комплект'];
        yield 'multiplication without agreed semantics' => ['2х3'];
        yield 'positions without agreed semantics' => [
            'Количество номенклатурных позиций — 2. По 3 шт. каждой номенклатурной позиции.',
        ];
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

    /**
     * @param array<string, int> $sampleQuantityOverrides
     * @param array<string, array{manufacturer?: string, supplier?: string}> $counterpartyOverrides
     */
    private function mapper(
        array $sampleQuantityOverrides = [],
        array $counterpartyOverrides = [],
    ): LegacyRequestMapper {
        return new LegacyRequestMapper([
            '77' => new LegacyUserData('77', 'ivanov', 'Иванов Иван', 'ivanov@example.test', null, true),
        ], $sampleQuantityOverrides, $counterpartyOverrides);
    }

    /**
     * @param array{manufacturer: string, supplier: string} $source
     * @param array{manufacturer?: string, supplier?: string} $override
     */
    private function mapCounterpartyDecision(string $legacyId, array $source, array $override): \App\Application\Import\LegacyRequestData
    {
        $element = $this->element($source);
        $element['ID'] = $legacyId;

        return $this->mapper(
            counterpartyOverrides: ["bitrix24:114:{$legacyId}" => $override],
        )->map($element, 114);
    }
}
