<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Domain\Request\AttentionQueue;
use yii\db\Connection;
use yii\db\Transaction;

final class RequestDashboardQuery
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Uses its own REPEATABLE READ snapshot when called without a transaction.
     * Inside an existing transaction, consistency and isolation remain the caller's responsibility.
     *
     * @return array{categories: list<array{id: string, title: string, description: string, count: int}>, operational_summary: array<string, mixed>}
     */
    public function findFor(int $actorId): array
    {
        $existingTransaction = $this->db->getTransaction();
        $transaction = $existingTransaction ?? $this->db->beginTransaction(Transaction::REPEATABLE_READ);
        try {
            $dashboard = [
                'categories' => $this->attentionCategories($actorId),
                'operational_summary' => (new OperationalSummaryQuery($this->db))->find($actorId),
            ];
            if ($existingTransaction === null) {
                $transaction->commit();
            }
            return $dashboard;
        } catch (\Throwable $error) {
            if ($existingTransaction === null && $transaction->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array{id: string, title: string, description: string, count: int}> */
    private function attentionCategories(int $actorId): array
    {
        $queues = AttentionQueue::cases();
        $scope = new AttentionQueueScope();
        $columns = [];
        foreach ($queues as $queue) {
            $columns[] = 'SUM(CASE WHEN ' . $scope->condition($queue) . ' THEN 1 ELSE 0 END) AS `'
                . $queue->value . '`';
        }
        $counts = $this->db->createCommand(
            'SELECT ' . implode(', ', $columns) . ' FROM {{%requests}} r',
            [':attention_actor' => $actorId],
        )->queryOne();

        $categories = [];
        foreach ($queues as $queue) {
            $count = (int) ($counts[$queue->value] ?? 0);
            if ($count === 0) {
                continue;
            }
            $categories[] = [
                'id' => $queue->value,
                'title' => $queue->title(),
                'description' => $queue->description(),
                'count' => $count,
            ];
        }
        return $categories;
    }
}
