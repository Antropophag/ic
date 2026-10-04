<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Infrastructure\Clock;
use App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter;
use Tests\Integration\IntegrationTestCase;
use yii\db\IntegrityException;

final class CurrentAssignmentDatabaseInvariantTest extends IntegrationTestCase
{
    public function testDatabaseAllowsHistoryButRejectsASecondCurrentAssignmentPerRequestAndType(): void
    {
        $actorId = $this->createUser('assignment-invariant-actor', 'Автор назначений');
        $firstUserId = $this->createUser('assignment-invariant-first', 'Первый исполнитель');
        $secondUserId = $this->createUser('assignment-invariant-second', 'Второй исполнитель');
        $firstRequestId = $this->createRequest($actorId, 'Первая заявка');
        $secondRequestId = $this->createRequest($actorId, 'Вторая заявка');
        $now = Clock::now();

        $this->insertAssignment($firstRequestId, $firstUserId, $actorId, $now);
        self::assertSame(1, $this->currentCount($firstRequestId));
        $this->insertAssignment($firstRequestId, $secondUserId, $actorId, $now, assignmentType: 'expert');
        self::assertSame(1, $this->currentCount($firstRequestId, 'expert'));
        self::assertSame(2, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%request_assignments}} '
            . 'WHERE request_id = :request_id AND valid_to IS NULL',
            [':request_id' => $firstRequestId],
        ));

        try {
            $this->insertAssignment($firstRequestId, $secondUserId, $actorId, $now);
            self::fail('The database accepted a second current assignment.');
        } catch (IntegrityException $error) {
            self::assertSame('23000', $error->errorInfo[0] ?? null);
        }

        $firstHistoricalValidTo = $this->timestampAfter($now, 1);
        $secondHistoricalValidTo = $this->timestampAfter($now, 2);
        $replacementAt = $this->timestampAfter($now, 3);
        $this->insertAssignment($firstRequestId, $secondUserId, $actorId, $now, $firstHistoricalValidTo);
        $this->insertAssignment($firstRequestId, $firstUserId, $actorId, $now, $secondHistoricalValidTo);
        self::assertSame(4, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%request_assignments}} WHERE request_id = :request_id',
            [':request_id' => $firstRequestId],
        ));

        $this->db()->createCommand()->update(
            '{{%request_assignments}}',
            ['valid_to' => $replacementAt],
            ['request_id' => $firstRequestId, 'assignment_type' => 'executor', 'valid_to' => null],
        )->execute();
        $this->insertAssignment($firstRequestId, $secondUserId, $actorId, $replacementAt);
        self::assertSame(1, $this->currentCount($firstRequestId));

        $this->insertAssignment($secondRequestId, $firstUserId, $actorId, $now);
        self::assertSame(1, $this->currentCount($firstRequestId));
        self::assertSame(1, $this->currentCount($secondRequestId));
    }

    private function createRequest(int $initiatorId, string $productName): int
    {
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => $productName,
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $request = (new \App\Application\Request\UseCase\CreateRequest(
            new RequestCreationPersistenceAdapter($this->db()),
        ))->execute($input->toCommand($initiatorId))->toArray();

        return (int) $request['id'];
    }

    private function insertAssignment(
        int $requestId,
        int $userId,
        int $actorId,
        string $validFrom,
        ?string $validTo = null,
        string $assignmentType = 'executor',
    ): void {
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => $assignmentType,
            'user_id' => $userId,
            'assigned_by' => $actorId,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ])->execute();
    }

    private function currentCount(int $requestId, string $assignmentType = 'executor'): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%request_assignments}} '
            . 'WHERE request_id = :request_id AND assignment_type = :assignment_type AND valid_to IS NULL',
            [':request_id' => $requestId, ':assignment_type' => $assignmentType],
        );
    }

    private function timestampAfter(string $timestamp, int $seconds): string
    {
        return (new \DateTimeImmutable($timestamp))->modify("+{$seconds} seconds")->format('Y-m-d H:i:s.u');
    }
}
