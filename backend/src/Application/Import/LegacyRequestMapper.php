<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Request\RequestStatus;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use UnexpectedValueException;

final class LegacyRequestMapper
{
    /** @param array<string, mixed> $element */
    public function map(array $element, int $listId): LegacyRequestData
    {
        $elementId = $this->requiredString($element, 'ID');
        if (preg_match('/^\d+$/', $elementId) !== 1) {
            throw new UnexpectedValueException('Legacy request ID must be numeric.');
        }
        $legacyId = "bitrix24:{$listId}:{$elementId}";
        $this->assertMaximumLength($legacyId, 128, 'legacyId');
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

        $creatorLegacyId = $this->requiredString($creator, 'ID');
        if (preg_match('/^\d+$/', $creatorLegacyId) !== 1) {
            throw new UnexpectedValueException('Legacy creator ID must be numeric.');
        }

        $productName = $this->requiredString($details, 'nameType');
        $manufacturer = $this->requiredString($details, 'manufacturer');
        $supplier = $this->requiredString($details, 'supplier');
        $testMethod = $this->string($details, 'testMethod');
        $creatorDisplayName = trim($this->string($creator, 'LAST_NAME') . ' ' . $this->string($creator, 'NAME'));
        $departmentName = $this->string($department, 'NAME');
        $departmentExternalId = $this->string($department, 'ID');
        $this->assertMaximumLength($productName, 500, 'nameType');
        $this->assertMaximumLength($manufacturer, 500, 'manufacturer');
        $this->assertMaximumLength($supplier, 500, 'supplier');
        $this->assertMaximumLength($creatorLegacyId, 112, 'creator.ID');
        $this->assertMaximumLength($creatorDisplayName, 255, 'creator.displayName');
        $this->assertMaximumLength($departmentName, 255, 'department.NAME');
        $this->assertMaximumLength($departmentExternalId, 128, 'department.ID');
        if (strlen($testMethod) > 65535) {
            throw new UnexpectedValueException('Legacy field testMethod exceeds the database limit.');
        }
        if ($quantity > 4294967295) {
            throw new UnexpectedValueException('Legacy request sample quantity exceeds the database limit.');
        }

        return new LegacyRequestData(
            $legacyId,
            $productName,
            $manufacturer,
            $supplier,
            $quantity,
            $testMethod,
            $this->status($this->requiredString($details, 'status')),
            $this->date($this->requiredString($details, 'dateCreate')),
            $creatorLegacyId,
            $creatorDisplayName,
            $departmentName,
            $this->count($details, 'supportingDocFiles'),
            $this->count($details, 'reportFiles'),
            $departmentExternalId !== '' ? $departmentExternalId : null,
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

    private function assertMaximumLength(string $value, int $maximum, string $field): void
    {
        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters > $maximum) {
            throw new UnexpectedValueException("Legacy field {$field} exceeds the database limit.");
        }
    }

    private function date(string $value): DateTimeImmutable
    {
        foreach ([[DateTimeInterface::ATOM, DateTimeInterface::ATOM], ['!Y-m-d', 'Y-m-d']] as [$format, $output]) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if (
                $date !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($output) === $value
            ) {
                return $date;
            }
        }
        throw new UnexpectedValueException('Legacy creation date has an invalid or unsupported format.');
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
