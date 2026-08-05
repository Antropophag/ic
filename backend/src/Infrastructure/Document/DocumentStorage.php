<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

final class DocumentStorage
{
    /** @var list<array{root: string, key: string}> */
    private static array $trackedWrites = [];
    /** @var list<array{token: int, start: int}> */
    private static array $trackingScopes = [];
    private static int $nextScopeToken = 0;

    public function __construct(private readonly string $root)
    {
    }

    public function store(string $sourcePath): string
    {
        $key = bin2hex(random_bytes(32));
        $directory = $this->root . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create document storage directory.');
        }
        $destination = $directory . '/' . $key;
        $temporary = $destination . '.part-' . bin2hex(random_bytes(8));
        try {
            if (!@copy($sourcePath, $temporary) || !chmod($temporary, 0600) || !rename($temporary, $destination)) {
                throw new \RuntimeException('Cannot store document.');
            }
        } finally {
            if (is_file($temporary)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- server-generated path under storage root
                unlink($temporary);
            }
        }
        if (self::$trackingScopes !== []) {
            self::$trackedWrites[] = ['root' => $this->root, 'key' => $key];
        }
        return $key;
    }

    public static function writeCheckpoint(): int
    {
        $token = ++self::$nextScopeToken;
        self::$trackingScopes[] = ['token' => $token, 'start' => count(self::$trackedWrites)];
        return $token;
    }

    public static function discardWritesSince(int $checkpoint): void
    {
        $scope = self::closeScope($checkpoint);
        if (self::$trackingScopes === []) {
            array_splice(self::$trackedWrites, $scope['start']);
        }
    }

    public static function rollbackWritesSince(int $checkpoint): void
    {
        $scope = self::closeScope($checkpoint);
        $writes = array_splice(self::$trackedWrites, $scope['start']);
        foreach (array_reverse($writes) as $write) {
            try {
                (new self($write['root']))->delete($write['key']);
            } catch (\Throwable $deleteError) {
                \Yii::error('Failed to compensate document write: ' . $deleteError->getMessage(), __METHOD__);
            }
        }
    }

    /** @return array{token: int, start: int} */
    private static function closeScope(int $checkpoint): array
    {
        $scope = array_pop(self::$trackingScopes);
        if ($scope === null) {
            throw new \LogicException('Document write scope is not open.');
        }
        if ($scope['token'] !== $checkpoint) {
            self::$trackingScopes[] = $scope;
            throw new \LogicException('Document write scopes must be closed in reverse order.');
        }
        return $scope;
    }

    public function assertWritable(): void
    {
        if (!is_dir($this->root) || !is_writable($this->root)) {
            throw new \RuntimeException('Document storage is not writable.');
        }
        $probe = tempnam($this->root, '.readiness-');
        if ($probe === false) {
            throw new \RuntimeException('Cannot create document storage probe.');
        }
        try {
            $payload = random_bytes(16);
            if (file_put_contents($probe, $payload, LOCK_EX) !== strlen($payload)) {
                throw new \RuntimeException('Cannot write document storage probe.');
            }
        } finally {
            if (is_file($probe)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam created path under storage root
                unlink($probe);
            }
        }
    }

    public function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            throw new \InvalidArgumentException('Invalid storage key.');
        }
        return $this->root . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- path() accepts only a 64-char server key
            unlink($path);
        }
    }
}
