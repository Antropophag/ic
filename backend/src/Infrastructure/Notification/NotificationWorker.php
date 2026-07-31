<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

final class NotificationWorker
{
    private bool $shutdownRequested = false;

    /**
     * @param callable(callable(): bool): int $processBatch
     * @param callable(int): void $sleep
     * @param callable(\Throwable): void $onIterationError
     */
    public function __construct(
        private readonly mixed $processBatch,
        private readonly mixed $sleep,
        private readonly mixed $onIterationError,
        private readonly int $idleSleep,
        private readonly int $errorSleep,
    ) {
    }

    public function requestShutdown(): void
    {
        $this->shutdownRequested = true;
    }

    public function run(): void
    {
        while (!$this->shutdownRequested) {
            try {
                $processed = ($this->processBatch)(
                    fn(): bool => !$this->isShutdownRequested(),
                );
                if ($processed === 0 && !$this->isShutdownRequested()) {
                    ($this->sleep)($this->idleSleep);
                }
            } catch (\Throwable $error) {
                ($this->onIterationError)($error);
                if (!$this->isShutdownRequested()) {
                    ($this->sleep)($this->errorSleep);
                }
            }
        }
    }

    private function isShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }
}
