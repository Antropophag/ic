<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

use RuntimeException;

final class BitrixListClient
{
    public function __construct(
        private readonly BitrixTransport $transport,
        private readonly string $iblockType,
        private readonly int $listId,
        private readonly int $pageDelayMilliseconds = 100,
    ) {
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        $response = $this->transport->call('lists.field.get', $this->baseParameters());
        return $this->result($response);
    }

    /** @return iterable<int, array<string, mixed>> */
    public function elements(int $maxPages = 0): iterable
    {
        foreach ($this->pages($maxPages) as $items) {
            foreach ($items as $item) {
                yield $item;
            }
        }
    }

    /** @return iterable<int, list<array<string, mixed>>> */
    public function pages(int $maxPages = 0): iterable
    {
        foreach ($this->pageBatches($maxPages) as $batch) {
            yield $batch['items'];
        }
    }

    /** @param list<string> $select
     *  @return iterable<int, array{items: list<array<string, mixed>>, total: ?int}>
     */
    public function pageBatches(int $maxPages = 0, array $select = []): iterable
    {
        $start = 0;
        $page = 0;
        do {
            $parameters = [
                ...$this->baseParameters(),
                'ELEMENT_ORDER' => ['ID' => 'asc'],
                'start' => $start,
            ];
            if ($select !== []) {
                $parameters['SELECT'] = $select;
            }
            $response = $this->transport->call('lists.element.get', $parameters);
            $result = $this->result($response);
            $items = [];
            foreach ($result as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('Bitrix24 returned an invalid list element.');
                }
                $items[] = $item;
            }
            $total = $response['total'] ?? null;
            if ($total !== null) {
                $total = filter_var($total, FILTER_VALIDATE_INT);
                if ($total === false || $total < 0) {
                    throw new RuntimeException('Bitrix24 returned an invalid total element count.');
                }
            }
            yield ['items' => $items, 'total' => $total];

            ++$page;
            $next = $response['next'] ?? null;
            if ($next !== null) {
                $nextStart = filter_var($next, FILTER_VALIDATE_INT);
                if ($nextStart === false || $nextStart <= $start) {
                    throw new RuntimeException('Bitrix24 returned an invalid pagination cursor.');
                }
                $start = $nextStart;
            }
            if ($next !== null && $this->pageDelayMilliseconds > 0) {
                usleep($this->pageDelayMilliseconds * 1000);
            }
        } while ($next !== null && ($maxPages === 0 || $page < $maxPages));
    }

    /** @return array{IBLOCK_TYPE_ID: string, IBLOCK_ID: int} */
    private function baseParameters(): array
    {
        return ['IBLOCK_TYPE_ID' => $this->iblockType, 'IBLOCK_ID' => $this->listId];
    }

    /** @param array<string, mixed> $response
     *  @return array<string, mixed>
     */
    private function result(array $response): array
    {
        if (!isset($response['result']) || !is_array($response['result'])) {
            throw new RuntimeException('Bitrix24 response has no result array.');
        }
        return $response['result'];
    }
}
