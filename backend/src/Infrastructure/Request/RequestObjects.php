<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use yii\db\Connection;

final class RequestObjects
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param list<array{productName: string, sampleQuantity: ?string}> $objects */
    public function insert(int $requestId, array $objects): void
    {
        foreach ($objects as $index => $object) {
            $this->db->createCommand()->insert('{{%request_objects}}', [
                'request_id' => $requestId,
                'position' => $index + 1,
                'product_name' => $object['productName'],
                'sample_quantity' => $object['sampleQuantity'],
            ])->execute();
        }
    }

    public function insertLegacy(int $requestId): void
    {
        $this->db->createCommand(
            'INSERT INTO {{%request_objects}} (request_id, position, product_name, sample_quantity) '
            . 'SELECT id, 1, product_name, COALESCE(CAST(sample_quantity AS CHAR), legacy_sample_quantity_raw) '
            . 'FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->execute();
    }

    /** @return list<array{productName: string, sampleQuantity: ?string}> */
    public function find(int $requestId): array
    {
        $rows = $this->db->createCommand(
            'SELECT product_name AS productName, sample_quantity AS sampleQuantity FROM {{%request_objects}} '
            . 'WHERE request_id = :id ORDER BY position',
            [':id' => $requestId],
        )->queryAll();
        if ($rows === []) {
            $rows = $this->db->createCommand(
                'SELECT product_name AS productName, COALESCE(CAST(sample_quantity AS CHAR), legacy_sample_quantity_raw) AS sampleQuantity '
                . 'FROM {{%requests}} WHERE id = :id',
                [':id' => $requestId],
            )->queryAll();
        }
        return array_map(static fn (array $row): array => [
            'productName' => (string) $row['productName'],
            'sampleQuantity' => $row['sampleQuantity'] === null ? null : (string) $row['sampleQuantity'],
        ], $rows);
    }

    /** @param list<array{productName: string, sampleQuantity: ?string}> $objects */
    public static function describe(array $objects): string
    {
        if (count($objects) === 1) {
            return $objects[0]['productName'];
        }
        $lines = [];
        foreach ($objects as $index => $object) {
            $lines[] = ($index + 1) . '. ' . $object['productName'] . ' — ' . ($object['sampleQuantity'] ?? 'количество не указано');
        }
        return implode("\n", $lines);
    }
}
