<?php

declare(strict_types=1);

namespace App\Application\Import;

use UnexpectedValueException;

final class LegacyUserMapper
{
    /** @param array<string, mixed> $user */
    public function map(array $user, string $expectedId): LegacyUserData
    {
        $id = $this->requiredString($user, 'ID');
        if ($id !== $expectedId || preg_match('/^\d+$/', $id) !== 1) {
            throw new UnexpectedValueException('Bitrix24 user ID does not match the requested creator.');
        }

        $email = strtolower($this->requiredString($user, 'EMAIL'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new UnexpectedValueException("Bitrix24 user {$id} has an invalid email.");
        }
        $separator = strpos($email, '@');
        $adLogin = $separator === false ? '' : substr($email, 0, $separator);
        if (preg_match('/^[a-z0-9._-]+$/', $adLogin) !== 1) {
            throw new UnexpectedValueException("Bitrix24 user {$id} email cannot produce a valid AD login.");
        }
        $this->assertMaximumLength($adLogin, 128, 'AD login');
        $this->assertMaximumLength($email, 255, 'email');

        $firstName = $this->requiredString($user, 'NAME');
        $lastName = $this->requiredString($user, 'LAST_NAME');
        $middleName = $this->string($user, 'SECOND_NAME');
        $displayName = implode(' ', array_filter([$lastName, $firstName, $middleName], static fn (string $part): bool => $part !== ''));
        $this->assertMaximumLength($displayName, 255, 'display name');
        $active = $user['ACTIVE'] ?? null;
        if (!in_array($active, [true, false, 'Y', 'N'], true)) {
            throw new UnexpectedValueException("Bitrix24 user {$id} has an invalid active flag.");
        }

        $position = $this->string($user, 'WORK_POSITION');
        $this->assertMaximumLength($position, 255, 'position');

        return new LegacyUserData(
            $id,
            $adLogin,
            $displayName,
            $email,
            $position !== '' ? $position : null,
            $active === true || $active === 'Y',
        );
    }

    /** @param array<string, mixed> $source */
    private function requiredString(array $source, string $key): string
    {
        $value = $this->string($source, $key);
        if ($value === '') {
            throw new UnexpectedValueException("Bitrix24 user field {$key} is required.");
        }
        return $value;
    }

    /** @param array<string, mixed> $source */
    private function string(array $source, string $key): string
    {
        return is_scalar($source[$key] ?? null) ? trim((string) $source[$key]) : '';
    }

    private function assertMaximumLength(string $value, int $maximum, string $field): void
    {
        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters > $maximum) {
            throw new UnexpectedValueException("Bitrix24 user {$field} exceeds the database limit.");
        }
    }
}
