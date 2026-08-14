<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\AddCommentCommand;
use App\Application\Request\UseCase\AddComment;
use App\Domain\Request\CommentDenied;
use App\Domain\Request\RequestNotFound;
use App\Http\Request\CreateRequest;
use App\Infrastructure\Clock;
use App\Infrastructure\Persistence\Request\RequestCommentPersistenceAdapter;
use App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter;
use Tests\Integration\IntegrationTestCase;

final class AddCommentTest extends IntegrationTestCase
{
    public function testSuccessPersistsCommentAuditAndUniqueRecipientsExceptAuthor(): void
    {
        $initiator = $this->createUser('comment.success.initiator', 'Инициатор', ' initiator@example.invalid ');
        $executor = $this->createUser('comment.success.executor', 'Исполнитель', 'executor@example.invalid');
        $requestId = $this->createRequest($initiator, 'success');
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'user_id' => $executor,
            'assignment_type' => 'executor',
            'assigned_by' => $initiator,
            'valid_from' => Clock::now(),
        ])->execute();

        $result = $this->useCase()->execute(new AddCommentCommand($requestId, $executor, 'Комментарий'));

        self::assertSame(['id', 'body', 'createdAt', 'authorName'], array_keys($result->toArray()));
        self::assertSame('Комментарий', $result->body);
        self::assertSame('Исполнитель', $result->authorName);
        $audit = $this->db()->createCommand(
            "SELECT rule_id, payload_json FROM {{%audit_events}} WHERE event_type = 'request.comment_added' "
            . 'AND entity_id = :id',
            [':id' => $requestId],
        )->queryOne();
        self::assertSame('COM-003', $audit['rule_id']);
        self::assertSame(['comment_id' => $result->id], json_decode($audit['payload_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['initiator@example.invalid'], $this->db()->createCommand(
            "SELECT recipient_email FROM {{%notification_outbox}} WHERE request_id = :id "
            . "AND event_type = 'request.commented' ORDER BY recipient_email",
            [':id' => $requestId],
        )->queryColumn());
    }

    public function testTerminalStatusIsDeniedWithoutSideEffects(): void
    {
        $actor = $this->createUser('comment.denied.actor', 'Автор');
        $requestId = $this->createRequest($actor, 'denied');
        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'completed'], ['id' => $requestId])->execute();

        try {
            $this->useCase()->execute(new AddCommentCommand($requestId, $actor, 'Нельзя'));
            self::fail('Expected comment denial.');
        } catch (CommentDenied) {
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%request_comments}} WHERE request_id = :id',
                [':id' => $requestId],
            ));
        }
    }

    public function testMissingRequestAndInactiveActorHaveTheExistingNotFoundSemantics(): void
    {
        $inactive = $this->createUser('comment.inactive.actor', 'Неактивный', null, false);
        $requestId = $this->createRequest($this->createUser('comment.active.initiator', 'Инициатор'), 'inactive');

        foreach ([[$requestId, $inactive], [PHP_INT_MAX, $inactive]] as [$id, $actor]) {
            try {
                $this->useCase()->execute(new AddCommentCommand($id, $actor, 'Комментарий'));
                self::fail('Expected not found.');
            } catch (RequestNotFound $error) {
                self::assertSame('Request not found', $error->getMessage());
            }
        }
    }

    private function useCase(): AddComment
    {
        return new AddComment(new RequestCommentPersistenceAdapter($this->db()));
    }

    private function createRequest(int $initiatorId, string $marker): int
    {
        $input = new CreateRequest();
        $input->setAttributes([
            'productName' => "Comment {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $result = (new \App\Application\Request\UseCase\CreateRequest(
            new RequestCreationPersistenceAdapter($this->db()),
        ))->execute($input->toCommand($initiatorId));
        return (int) $result->toArray()['id'];
    }
}
