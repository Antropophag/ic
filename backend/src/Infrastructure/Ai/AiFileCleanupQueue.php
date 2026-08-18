<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

interface AiFileCleanupQueue
{
    public function schedule(string $externalFileId, \Throwable $error): void;
}
