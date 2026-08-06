<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

use RuntimeException;

final class BitrixUserClient
{
    public function __construct(
        private readonly BitrixTransport $transport,
        private readonly int $requestDelayMilliseconds = 50,
    ) {
    }

    /** @param list<string> $ids
     *  @return array<int|string, array<string, mixed>>
     */
    public function usersById(array $ids): array
    {
        $users = [];
        $uniqueIds = array_values(array_unique($ids));
        foreach ($uniqueIds as $index => $id) {
            $response = $this->transport->call('user.get', ['FILTER' => ['ID' => $id]]);
            $result = $response['result'] ?? null;
            if (!is_array($result) || count($result) !== 1 || !is_array($result[0])) {
                throw new RuntimeException("Bitrix24 user {$id} was not found unambiguously.");
            }
            $returnedId = $result[0]['ID'] ?? null;
            if ((!is_string($returnedId) && !is_int($returnedId)) || (string) $returnedId !== $id) {
                throw new RuntimeException("Bitrix24 returned a different user for requested ID {$id}.");
            }
            $users[$id] = $result[0];
            if ($index < count($uniqueIds) - 1 && $this->requestDelayMilliseconds > 0) {
                usleep($this->requestDelayMilliseconds * 1000);
            }
        }
        return $users;
    }
}
