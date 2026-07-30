<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\StderrTarget;

final class RecordingStderrTarget extends StderrTarget
{
    /** @var list<string> */
    public array $written = [];

    public function __construct(private readonly int|false|null $result = null)
    {
        parent::__construct();
    }

    protected function write(string $message): int|false
    {
        $this->written[] = $message;
        return $this->result ?? strlen($message);
    }
}
