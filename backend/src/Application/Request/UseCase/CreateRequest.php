<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\CreateRequestCommand;
use App\Application\Request\CreateRequestResult;
use App\Application\Request\Port\RequestCreationGateway;
use App\Domain\Request\RequestCreationPolicy;
use App\Domain\Request\RequestDepartmentMissing;

final readonly class CreateRequest
{
    public function __construct(
        private RequestCreationGateway $gateway,
        private RequestCreationPolicy $policy = new RequestCreationPolicy(),
    ) {
    }

    public function execute(CreateRequestCommand $command): CreateRequestResult
    {
        return $this->gateway->transactional(function () use ($command): CreateRequestResult {
            $context = $this->gateway->creationContext($command->initiatorId);
            $this->policy->assertCanCreate($context->initiatorRoles, $context->initiatorActive);
            $department = $this->gateway->departmentSnapshotForUpdate($command->initiatorId);
            if ($department === null) {
                throw new RequestDepartmentMissing(
                    'В профиле пользователя не указано подразделение. Обратитесь к администратору.',
                );
            }

            $number = $this->gateway->allocateNumber();
            $createdAt = $this->gateway->creationTimestamp();
            $requestId = $this->gateway->insertRequest($command, $department, $number, $createdAt);
            $this->gateway->recordCreation($requestId, $command->initiatorId, $createdAt);
            $this->gateway->enqueueCreationNotifications(
                $requestId,
                $number,
                $command->productName,
                $command->initiatorId,
            );

            return new CreateRequestResult($this->gateway->createdRequest($requestId));
        });
    }

    public function recordRejected(CreateRequestCommand $command, string $ruleId): void
    {
        $this->gateway->recordRejectedCreation($command->initiatorId, $ruleId);
    }
}
