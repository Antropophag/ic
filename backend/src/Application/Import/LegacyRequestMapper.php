<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Request\RequestStatus;
use DateTimeImmutable;
use UnexpectedValueException;

final class LegacyRequestMapper
{
    /** @param array<string, mixed> $element */
    public function map(array $element, int $listId): LegacyRequestData
    {
        $elementId = $this->requiredString($element, 'ID');
        $raw = $this->requiredString($element, 'DETAIL_TEXT');
        $details = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new UnexpectedValueException('Legacy request details must be an object.');
        }

        $creator = is_array($details['creator'] ?? null) ? $details['creator'] : [];
        $department = is_array($details['department'] ?? null) ? $details['department'] : [];
        $quantity = $this->quantity($this->string($details, 'countTestItems'));
        if ($quantity < 1) {
            throw new UnexpectedValueException('Legacy request sample quantity must be positive.');
        }

        return new LegacyRequestData(
            "bitrix24:{$listId}:{$elementId}",
            $this->requiredString($details, 'nameType'),
            $this->requiredString($details, 'manufacturer'),
            $this->requiredString($details, 'supplier'),
            $quantity,
            $this->string($details, 'testMethod'),
            $this->status($this->requiredString($details, 'status')),
            new DateTimeImmutable($this->requiredString($details, 'dateCreate')),
            $this->requiredString($creator, 'ID'),
            trim($this->string($creator, 'LAST_NAME') . ' ' . $this->string($creator, 'NAME')),
            $this->string($department, 'NAME'),
            $this->count($details, 'supportingDocFiles'),
            $this->count($details, 'reportFiles'),
        );
    }

    /** @param array<string, mixed> $source */
    private function requiredString(array $source, string $key): string
    {
        $value = $this->string($source, $key);
        if ($value === '') {
            throw new UnexpectedValueException("Legacy field {$key} is required.");
        }
        return $value;
    }

    /** @param array<string, mixed> $source */
    private function string(array $source, string $key): string
    {
        return is_scalar($source[$key] ?? null) ? trim((string) $source[$key]) : '';
    }

    /** @param array<string, mixed> $source */
    private function count(array $source, string $key): int
    {
        return is_array($source[$key] ?? null) ? count($source[$key]) : 0;
    }

    private function quantity(string $value): int
    {
        if (preg_match('/^\s*(\d+)(?:[.,]0+)?(?:\s|$)/u', $value, $matches) !== 1) {
            return 0;
        }
        return (int) $matches[1];
    }

    private function status(string $status): RequestStatus
    {
        return match ($status) {
            'Заявка зарегистрирована' => RequestStatus::Registered,
            'Заявка в работе' => RequestStatus::InProgress,
            'Работы приостановлены' => RequestStatus::Suspended,
            'Подготовка заключения' => RequestStatus::OpinionPreparation,
            'Контроль СБ' => RequestStatus::SecurityReview,
            'Выполнено', 'Заявка выполнена' => RequestStatus::Completed,
            'Отказано' => RequestStatus::Rejected,
            'Заявка отозвана' => RequestStatus::Withdrawn,
            default => throw new UnexpectedValueException("Unknown legacy status: {$status}"),
        };
    }
}
