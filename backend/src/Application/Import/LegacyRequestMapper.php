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
    /**
     * @param array<int|string, LegacyUserData> $usersById
     * @param array<string, int> $sampleQuantityOverrides Controlled, snapshot-validated legacy values.
     * @param array<string, array{manufacturer?: string, supplier?: string}> $counterpartyOverrides
     */
    public function __construct(
        private readonly array $usersById,
        private readonly array $sampleQuantityOverrides = [],
        private readonly array $counterpartyOverrides = [],
    ) {
    }

    /** @param array<string, mixed> $element */
    public function map(array $element, int $listId): LegacyRequestData
    {
        $elementId = $this->requiredString($element, 'ID');
        if (preg_match('/^\d+$/', $elementId) !== 1) {
            throw new UnexpectedValueException('Legacy request ID must be numeric.');
        }
        $number = filter_var($elementId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            throw new UnexpectedValueException('Legacy request ID must fit a positive integer.');
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
        $rawQuantity = $details['countTestItems'] ?? null;
        $legacySampleQuantityRaw = is_string($rawQuantity) || is_int($rawQuantity)
            ? (string) $rawQuantity
            : null;
        $quantity = $this->quantity($legacySampleQuantityRaw)
            ?? ($this->sampleQuantityOverrides[$legacyId] ?? null);

        $creatorLegacyId = $this->string($creator, 'ID');
        if (preg_match('/^\d+$/', $creatorLegacyId) !== 1) {
            $creatorLegacyId = $this->string($element, 'CREATED_BY');
        }
        if ($creatorLegacyId === '') {
            throw new UnexpectedValueException('Legacy creator ID is required.');
        }
        if (preg_match('/^\d+$/', $creatorLegacyId) !== 1) {
            throw new UnexpectedValueException('Legacy creator ID must be numeric.');
        }

        $productName = $this->requiredString($details, 'nameType');
        $manufacturer = $this->counterparty($details, $legacyId, 'manufacturer');
        $supplier = $this->counterparty($details, $legacyId, 'supplier');
        $testMethod = $this->string($details, 'testMethod');
        $creatorProfile = $this->usersById[$creatorLegacyId] ?? null;
        if (!$creatorProfile instanceof LegacyUserData) {
            throw new UnexpectedValueException("Bitrix24 creator {$creatorLegacyId} has no mapped user profile.");
        }
        $departmentName = $this->string($department, 'NAME');
        $departmentExternalId = $this->string($department, 'ID');
        $this->assertMaximumLength($productName, 2000, 'nameType');
        $this->assertMaximumLength($manufacturer, 500, 'manufacturer');
        $this->assertMaximumLength($supplier, 500, 'supplier');
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
            $number,
            $productName,
            $manufacturer,
            $supplier,
            $quantity,
            $legacySampleQuantityRaw,
            $testMethod,
            $this->status($this->requiredString($details, 'status')),
            $this->date($this->requiredString($details, 'dateCreate')),
            $creatorProfile,
            $departmentName,
            $this->count($details, 'supportingDocFiles'),
            $this->count($details, 'reportFiles'),
            $departmentExternalId !== '' ? $departmentExternalId : null,
            $this->comments($details, $legacyId),
        );
    }

    /** @param array<string, mixed> $details
     *  @return list<LegacyCommentData>
     */
    private function comments(array $details, string $requestLegacyId): array
    {
        $result = [];
        /** @var array<string, LegacyCommentData> $legacyComments */
        $legacyComments = [];
        $userMapper = new LegacyUserMapper();
        foreach (['commentsInitiator', 'commentsIC'] as $group) {
            $comments = $details[$group] ?? [];
            if (!is_array($comments)) {
                throw new UnexpectedValueException("Legacy field {$group} must be an array.");
            }
            foreach ($comments as $index => $comment) {
                if (!is_array($comment)) {
                    throw new UnexpectedValueException('Legacy comment must be an object.');
                }
                if ($this->emptyTechnicalComment($comment)) {
                    continue;
                }
                $id = $this->string($comment, 'id');
                if ($id === '') {
                    $id = "index-{$index}";
                }
                $creator = is_array($comment['creator'] ?? null) ? $comment['creator'] : [];
                $creatorId = $this->requiredString($creator, 'ID');
                $legacyId = "{$requestLegacyId}:comment:{$group}:{$id}";
                $this->assertMaximumLength($legacyId, 191, 'comment.legacyId');
                $mappedComment = new LegacyCommentData(
                    $legacyId,
                    is_string($comment['text'] ?? null) ? trim($comment['text']) : '',
                    $this->commentDate($this->requiredString($comment, 'dateCreated')),
                    $userMapper->map($creator, $creatorId),
                    $this->count($comment, 'files'),
                );
                if (isset($legacyComments[$legacyId])) {
                    if ($legacyComments[$legacyId] == $mappedComment) {
                        continue;
                    }
                    throw new UnexpectedValueException("Conflicting duplicate legacy comment ID: {$legacyId}.");
                }
                $legacyComments[$legacyId] = $mappedComment;
                $result[] = $mappedComment;
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $comment */
    private function emptyTechnicalComment(array $comment): bool
    {
        return count($comment) === 1
            && array_key_exists('files', $comment)
            && is_array($comment['files'])
            && $comment['files'] === [];
    }

    /** @param array<string, mixed> $details */
    private function counterparty(array $details, string $legacyId, string $field): string
    {
        $value = $this->string($details, $field);
        if ($value === '') {
            $value = $this->counterpartyOverrides[$legacyId][$field] ?? '';
        }
        if ($value === '') {
            throw new UnexpectedValueException("Legacy field {$field} is required.");
        }
        return $value;
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
        return is_string($source[$key] ?? null) ? trim($source[$key]) : '';
    }

    /** @param array<string, mixed> $source */
    private function count(array $source, string $key): int
    {
        return is_array($source[$key] ?? null) ? count($source[$key]) : 0;
    }

    private function quantity(?string $value): ?int
    {
        if ($value === null || preg_match('/^\s*(\d+)(?:[.,]0+)?(?:\s*шт\.?)?\s*$/ui', $value, $matches) !== 1) {
            return null;
        }
        $quantity = (int) $matches[1];
        return $quantity >= 1 ? $quantity : null;
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

    private function commentDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!d.m.Y в H:i', $value, new DateTimeZone('Europe/Moscow'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new UnexpectedValueException('Legacy comment date has an invalid format.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private function status(string $status): RequestStatus
    {
        return match ($status) {
            'Заявка зарегистрирована' => RequestStatus::Registered,
            'Заявка в работе', 'В работе' => RequestStatus::InProgress,
            'Работы приостановлены', 'Приостановлено' => RequestStatus::Suspended,
            'Подготовка заключения' => RequestStatus::OpinionPreparation,
            'Контроль СБ' => RequestStatus::SecurityReview,
            'Выполнено', 'Заявка выполнена' => RequestStatus::Completed,
            'Отказано', 'В проведении испытаний отказано' => RequestStatus::Rejected,
            'Заявка отозвана' => RequestStatus::Withdrawn,
            default => throw new UnexpectedValueException("Unknown legacy status: {$status}"),
        };
    }
}
