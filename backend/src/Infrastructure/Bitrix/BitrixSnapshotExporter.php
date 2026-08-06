<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class BitrixSnapshotExporter
{
    private const FORMAT_VERSION = 2;

    /** @return array<string, mixed> */
    public function export(
        BitrixListClient $client,
        BitrixUserClient $userClient,
        string $destination,
        string $iblockType,
        int $listId,
        int $maxPages = 0,
    ): array {
        $destination = rtrim($destination, DIRECTORY_SEPARATOR);
        if ($destination === '') {
            throw new RuntimeException('Snapshot destination must not be empty.');
        }

        $partial = $destination . '.partial';
        if (file_exists($destination) || file_exists($partial)) {
            throw new RuntimeException('Snapshot destination or its partial directory already exists.');
        }
        if (!is_dir(dirname($destination))) {
            throw new RuntimeException('Snapshot parent directory does not exist.');
        }
        $this->assertOutsideGitWorkTree(dirname($destination));
        if (!mkdir($partial, 0700) || !chmod($partial, 0700)) {
            throw new RuntimeException('Cannot create a private snapshot directory.');
        }

        $startedAt = $this->now();
        try {
            $fieldsPath = $partial . '/fields.json';
            $fields = $client->fields();
            $this->writeJson($fieldsPath, $fields);
            $selection = $this->selection($fields);

            $elementsPath = $partial . '/elements.jsonl';
            $elements = $this->openPrivateFile($elementsPath);
            $records = 0;
            $pages = 0;
            $sourceTotal = null;
            $creatorIds = [];
            try {
                foreach ($client->pageBatches($maxPages, $selection) as $batch) {
                    ++$pages;
                    if ($batch['total'] !== null) {
                        if ($sourceTotal !== null && $sourceTotal !== $batch['total']) {
                            throw new RuntimeException('Bitrix24 total changed while the snapshot was being read.');
                        }
                        $sourceTotal = $batch['total'];
                    }
                    foreach ($batch['items'] as $element) {
                        $line = json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        $this->writeAll($elements, $line . "\n");
                        ++$records;
                        $creatorId = $this->creatorId($element);
                        if ($creatorId !== null) {
                            $creatorIds[] = $creatorId;
                        }
                    }
                }
            } finally {
                fclose($elements);
            }
            if ($maxPages === 0 && $sourceTotal !== null && $records !== $sourceTotal) {
                throw new RuntimeException('Complete snapshot record count does not match the Bitrix24 total.');
            }

            $usersPath = $partial . '/users.json';
            $rawUsers = $userClient->usersById($creatorIds);
            $users = [];
            foreach ($rawUsers as $user) {
                $users[] = array_intersect_key($user, array_flip([
                    'ID',
                    'ACTIVE',
                    'NAME',
                    'LAST_NAME',
                    'SECOND_NAME',
                    'EMAIL',
                    'WORK_POSITION',
                ]));
            }
            usort($users, static fn (array $left, array $right): int => (int) ($left['ID'] ?? 0) <=> (int) ($right['ID'] ?? 0));
            $this->writeJson($usersPath, $users);

            $manifest = [
                'formatVersion' => self::FORMAT_VERSION,
                'complete' => true,
                'startedAt' => $startedAt,
                'completedAt' => $this->now(),
                'source' => ['iblockType' => $iblockType, 'listId' => $listId],
                'selectedFields' => $selection,
                'limits' => ['maxPages' => $maxPages],
                'pages' => $pages,
                'records' => $records,
                'users' => count($users),
                'sourceTotal' => $sourceTotal,
                'files' => [
                    'fields.json' => $this->fileMetadata($fieldsPath),
                    'elements.jsonl' => $this->fileMetadata($elementsPath),
                    'users.json' => $this->fileMetadata($usersPath),
                ],
            ];
            $this->writeJson($partial . '/manifest.json', $manifest);
            if (!rename($partial, $destination)) {
                throw new RuntimeException('Cannot publish the completed snapshot.');
            }
            return $manifest;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Snapshot was not completed; partial data remains in {$partial}.",
                0,
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $element */
    private function creatorId(array $element): ?string
    {
        $raw = $element['DETAIL_TEXT'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        try {
            $details = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $id = is_array($details) && is_array($details['creator'] ?? null)
            ? trim((string) ($details['creator']['ID'] ?? ''))
            : '';
        return preg_match('/^\d+$/', $id) === 1 ? $id : null;
    }

    /** @param array<array-key, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $handle = $this->openPrivateFile($path);
        try {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->writeAll($handle, $json . "\n");
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function openPrivateFile(string $path)
    {
        $handle = fopen($path, 'x+b');
        if ($handle === false || !chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Cannot create a private snapshot file.');
        }
        return $handle;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write snapshot data.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Cannot flush snapshot data.');
        }
    }

    /** @return array{bytes: int, sha256: string} */
    private function fileMetadata(string $path): array
    {
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if ($bytes === false || $sha256 === false) {
            throw new RuntimeException('Cannot calculate snapshot file metadata.');
        }
        return ['bytes' => $bytes, 'sha256' => $sha256];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @param array<string, mixed> $fields
     *  @return list<string>
     */
    private function selection(array $fields): array
    {
        $selection = [
            'ID',
            'CODE',
            'NAME',
            'IBLOCK_ID',
            'IBLOCK_SECTION_ID',
            'CREATED_BY',
            'DATE_CREATE',
            'BP_PUBLISHED',
            'DETAIL_TEXT',
            'DETAIL_TEXT_TYPE',
        ];
        foreach (array_keys($fields) as $field) {
            if (str_starts_with($field, 'PROPERTY_')) {
                $selection[] = $field;
            }
        }
        return array_values(array_unique($selection));
    }

    private function assertOutsideGitWorkTree(string $directory): void
    {
        $current = realpath($directory);
        if ($current === false) {
            throw new RuntimeException('Cannot resolve the snapshot parent directory.');
        }
        do {
            if (file_exists($current . '/.git')) {
                throw new RuntimeException('Snapshot destination must be outside a Git working tree.');
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        } while (true);
    }
}
