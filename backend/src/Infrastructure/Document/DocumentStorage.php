<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

final class DocumentStorage
{
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
        if (!copy($sourcePath, $destination)) {
            throw new \RuntimeException('Cannot store document.');
        }
        chmod($destination, 0600);
        return $key;
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
            unlink($path);
        }
    }
}
