<?php

declare(strict_types=1);

namespace App\Application\Import;

use Generator;
use JsonException;
use RuntimeException;

final class BitrixSnapshotReader
{
    /** @return array{listId: int, fingerprint: string, users: array<int|string, LegacyUserData>, elements: iterable<array<string, mixed>>} */
    public function read(string $directory): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $manifest = $this->json($directory . '/manifest.json');
        if (($manifest['complete'] ?? null) !== true || ($manifest['formatVersion'] ?? null) !== 2) {
            throw new RuntimeException('Snapshot must be a complete format 2 snapshot.');
        }
        foreach (['fields.json', 'elements.jsonl', 'users.json'] as $name) {
            $metadata = $manifest['files'][$name] ?? null;
            $path = $directory . '/' . $name;
            if (
                !is_array($metadata) || !is_file($path)
                || filesize($path) !== ($metadata['bytes'] ?? null)
                || hash_file('sha256', $path) !== ($metadata['sha256'] ?? null)
            ) {
                throw new RuntimeException("Snapshot integrity check failed for {$name}.");
            }
        }
        $rawUsers = $this->json($directory . '/users.json');
        if (!array_is_list($rawUsers)) {
            throw new RuntimeException('Snapshot users must be a list.');
        }
        $mapper = new LegacyUserMapper();
        $users = [];
        foreach ($rawUsers as $index => $rawUser) {
            if (!is_array($rawUser)) {
                throw new RuntimeException('Snapshot user must be an object.');
            }
            $rawId = $rawUser['ID'] ?? null;
            if ((!is_string($rawId) && !is_int($rawId)) || preg_match('/^\d+$/D', (string) $rawId) !== 1) {
                throw new RuntimeException("Snapshot user ID at index {$index} must be a non-empty integer.");
            }
            $id = (string) $rawId;
            if (isset($users[$id])) {
                throw new RuntimeException("Duplicate snapshot user ID {$id} at index {$index}.");
            }
            $users[$id] = $mapper->map($rawUser, $id);
        }
        $listId = filter_var($manifest['source']['listId'] ?? null, FILTER_VALIDATE_INT);
        if ($listId === false) {
            throw new RuntimeException('Snapshot list ID is invalid.');
        }
        return [
            'listId' => $listId,
            'fingerprint' => (string) $manifest['files']['elements.jsonl']['sha256'],
            'users' => $users,
            'elements' => $this->elements($directory . '/elements.jsonl'),
        ];
    }

    /** @return Generator<int, array<string, mixed>> */
    private function elements(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open snapshot elements.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($value)) {
                    throw new RuntimeException('Snapshot element must be an object.');
                }
                yield $value;
            }
            if (!feof($handle)) {
                throw new RuntimeException('Cannot finish reading snapshot elements.');
            }
        } catch (JsonException $error) {
            throw new RuntimeException('Snapshot contains invalid JSON.', 0, $error);
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int|string, mixed> */
    private function json(string $path): array
    {
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("Invalid JSON in {$path}.", 0, $error);
        }
        if (!is_array($value)) {
            throw new RuntimeException("Expected JSON object or list in {$path}.");
        }
        return $value;
    }
}
