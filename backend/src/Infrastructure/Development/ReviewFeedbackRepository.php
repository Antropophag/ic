<?php

declare(strict_types=1);

namespace App\Infrastructure\Development;

use yii\db\Connection;

final class ReviewFeedbackRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<string> $checklist
     * @return array{id: int, body: string, checklist: list<string>, createdAt: string, authorName: string, authorPosition: string}
     */
    public function create(int $authorId, string $body, array $checklist): array
    {
        $createdAt = gmdate('Y-m-d H:i:s.u');
        $this->db->createCommand()->insert('{{%review_feedback}}', [
            'author_id' => $authorId,
            'body' => $body,
            'checklist_json' => $checklist,
            'created_at' => $createdAt,
        ])->execute();

        return $this->find((int) $this->db->getLastInsertID());
    }

    /** @return list<array{id: int, body: string, checklist: list<string>, createdAt: string, authorName: string, authorPosition: string}> */
    public function latest(int $limit = 100): array
    {
        $rows = $this->db->createCommand(
            'SELECT feedback.id, feedback.body, feedback.checklist_json AS checklist, '
            . 'feedback.created_at AS createdAt, author.display_name AS authorName, '
            . 'author.position AS authorPosition FROM {{%review_feedback}} feedback '
            . 'INNER JOIN {{%users}} author ON author.id = feedback.author_id '
            . 'ORDER BY feedback.id DESC LIMIT ' . max(1, min($limit, 100)),
        )->queryAll();

        return array_map($this->normalize(...), $rows);
    }

    /** @return array{id: int, body: string, checklist: list<string>, createdAt: string, authorName: string, authorPosition: string} */
    private function find(int $id): array
    {
        $row = $this->db->createCommand(
            'SELECT feedback.id, feedback.body, feedback.checklist_json AS checklist, '
            . 'feedback.created_at AS createdAt, author.display_name AS authorName, '
            . 'author.position AS authorPosition FROM {{%review_feedback}} feedback '
            . 'INNER JOIN {{%users}} author ON author.id = feedback.author_id '
            . 'WHERE feedback.id = :id',
            [':id' => $id],
        )->queryOne();

        if (!is_array($row)) {
            throw new \RuntimeException('Created review feedback cannot be read.');
        }

        return $this->normalize($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, body: string, checklist: list<string>, createdAt: string, authorName: string, authorPosition: string}
     */
    private function normalize(array $row): array
    {
        $checklist = json_decode((string) $row['checklist'], true, 512, JSON_THROW_ON_ERROR);
        $normalizedChecklist = is_array($checklist)
            ? array_values(array_filter($checklist, is_string(...)))
            : [];
        return [
            'id' => (int) $row['id'],
            'body' => (string) $row['body'],
            'checklist' => $normalizedChecklist,
            'createdAt' => (string) $row['createdAt'],
            'authorName' => (string) $row['authorName'],
            'authorPosition' => (string) $row['authorPosition'],
        ];
    }
}
