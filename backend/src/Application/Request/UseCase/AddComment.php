<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\AddCommentResult;
use App\Application\Request\Command\AddCommentCommand;
use App\Application\Request\Port\RequestCommentGateway;
use App\Domain\Request\CommentPolicy;
use App\Domain\Request\RequestNotFound;

final readonly class AddComment
{
    public function __construct(
        private RequestCommentGateway $gateway,
        private CommentPolicy $policy = new CommentPolicy(),
    ) {
    }

    public function execute(AddCommentCommand $command): AddCommentResult
    {
        return $this->gateway->transactional(function () use ($command): AddCommentResult {
            $status = $this->gateway->statusForActiveActorForUpdate($command->requestId, $command->actorId);
            if ($status === null) {
                throw new RequestNotFound('Request not found');
            }
            $this->policy->assertCanAdd($status);

            $createdAt = $this->gateway->commentTimestamp();
            $commentId = $this->gateway->persistComment(
                $command->requestId,
                $command->actorId,
                $command->body,
                $createdAt,
            );
            $this->gateway->recordCommentAdded(
                $command->requestId,
                $command->actorId,
                $commentId,
                $createdAt,
            );
            $this->gateway->enqueueCommentNotifications($command->requestId, $command->actorId);

            return $this->gateway->commentResult($commentId);
        });
    }
}
