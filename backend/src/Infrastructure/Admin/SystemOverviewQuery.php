<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin;

use Closure;
use Throwable;
use Yii;

final class SystemOverviewQuery
{
    /**
     * @param array<string, ?string> $application
     * @param array<string, array<string, string>> $serviceDetails
     * @param array<string, Closure(): array<string, string>> $probes
     */
    public function __construct(
        private readonly array $application,
        private readonly array $serviceDetails,
        private readonly array $probes,
    ) {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $services = [];
        foreach ($this->probes as $id => $probe) {
            try {
                $details = array_merge($this->serviceDetails[$id] ?? [], $probe());
                $services[$id] = [
                    'status' => 'operational',
                    'message' => 'Соединение установлено',
                    'details' => $details,
                ];
            } catch (Throwable $error) {
                Yii::warning("System overview probe failed for {$id} (" . $error::class . ')', __METHOD__);
                $services[$id] = [
                    'status' => 'error',
                    'message' => 'Не удалось установить соединение',
                    'details' => $this->serviceDetails[$id] ?? [],
                ];
            }
        }
        return ['application' => $this->application, 'services' => $services, 'checkedAt' => gmdate('Y-m-d\TH:i:s\Z')];
    }
}
