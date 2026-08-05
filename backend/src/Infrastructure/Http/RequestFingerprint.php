<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use JsonException;
use yii\web\Request;

final class RequestFingerprint
{
    /**
     * @param array<string, mixed> $formFields
     * @param array<string, mixed> $files
     */
    public static function fromRequest(Request $request, array $formFields, array $files): string
    {
        $contentType = strtolower(trim(explode(';', $request->contentType ?? '', 2)[0]));
        if ($contentType === 'application/json') {
            return self::json($request->rawBody);
        }
        if ($contentType === 'multipart/form-data') {
            return self::multipart($formFields, $files);
        }
        return hash('sha256', $request->rawBody);
    }

    public static function json(string $rawBody): string
    {
        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return hash('sha256', $rawBody);
        }
        return hash('sha256', self::encodeCanonical($decoded));
    }

    /**
     * @param array<string, mixed> $formFields
     * @param array<string, mixed> $files
     */
    public static function multipart(array $formFields, array $files): string
    {
        $descriptors = [];
        ksort($files, SORT_STRING);
        foreach ($files as $field => $file) {
            if (!is_array($file)) {
                continue;
            }
            self::collectFiles((string) $field, $file, $file['tmp_name'] ?? null, [], $descriptors);
        }
        sort($descriptors, SORT_STRING);
        return hash('sha256', self::encodeCanonical([
            'fields' => self::normalize($formFields),
            'files' => $descriptors,
        ]));
    }

    /**
     * @param array<string, mixed> $file
     * @param list<string|int> $path
     * @param list<string> $descriptors
     */
    private static function collectFiles(
        string $field,
        array $file,
        mixed $temporaryName,
        array $path,
        array &$descriptors,
    ): void {
        if (is_array($temporaryName)) {
            foreach ($temporaryName as $index => $nested) {
                self::collectFiles($field, $file, $nested, [...$path, $index], $descriptors);
            }
            return;
        }
        $descriptors[] = self::encodeCanonical([
            'field' => $field,
            'name' => self::atPath($file['name'] ?? null, $path),
            'type' => self::atPath($file['type'] ?? null, $path),
            'size' => self::atPath($file['size'] ?? null, $path),
            'error' => self::atPath($file['error'] ?? null, $path),
            'sha256' => is_string($temporaryName) && is_file($temporaryName)
                ? hash_file('sha256', $temporaryName)
                : null,
        ]);
    }

    /** @param list<string|int> $path */
    private static function atPath(mixed $value, array $path): mixed
    {
        foreach ($path as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private static function encodeCanonical(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}
