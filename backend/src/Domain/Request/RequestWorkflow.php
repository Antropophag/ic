<?php

declare(strict_types=1);

namespace App\Domain\Request;

/**
 * Единственная таблица переходов жизненного цикла заявки.
 *
 * Контроллеры не должны устанавливать статус напрямую: это защищает одинаковые
 * правила для UI, импорта и будущих интеграций. Идентификаторы ссылаются на
 * docs/business-rules.md и попадают в ответ об ошибке и аудит.
 */
final class RequestWorkflow
{
    /** @param list<Role> $roles */
    public function transition(RequestStatus $from, RequestAction $action, array $roles): RequestStatus
    {
        if (in_array($action, [RequestAction::Reject, RequestAction::Withdraw], true)) {
            return $this->cancellationTransition($from, $action, $roles);
        }

        $target = match ([$from, $action]) {
            [RequestStatus::Registered, RequestAction::Start] => $this->forRoles(
                $roles,
                [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager],
                RequestStatus::InProgress,
                'WF-004',
                $from,
                $action,
            ),
            [RequestStatus::InProgress, RequestAction::Suspend] => $this->forRoles(
                $roles,
                [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager],
                RequestStatus::Suspended,
                'WF-005',
                $from,
                $action,
            ),
            [RequestStatus::Suspended, RequestAction::Resume] => $this->forRoles(
                $roles,
                [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager],
                RequestStatus::InProgress,
                'WF-005',
                $from,
                $action,
            ),
            [RequestStatus::InProgress, RequestAction::UploadReport] => $this->forRoles(
                $roles,
                [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager],
                RequestStatus::OpinionPreparation,
                'DOC-001',
                $from,
                $action,
            ),
            [RequestStatus::OpinionPreparation, RequestAction::PublishOpinion] => $this->forRoles(
                $roles,
                [Role::Expert],
                RequestStatus::SecurityReview,
                'DOC-005',
                $from,
                $action,
            ),
            [RequestStatus::SecurityReview, RequestAction::SecurityApprove] => $this->forRoles(
                $roles,
                [Role::SecurityOfficer],
                RequestStatus::Completed,
                'SEC-002',
                $from,
                $action,
            ),
            [RequestStatus::SecurityReview, RequestAction::SecurityReturn] => $this->forRoles(
                $roles,
                [Role::SecurityOfficer],
                RequestStatus::InProgress,
                'SEC-003',
                $from,
                $action,
            ),
            default => null,
        };

        if ($target === null) {
            throw new TransitionDenied('WF-003', $from, $action);
        }

        return $target;
    }

    /** @param list<Role> $roles */
    private function cancellationTransition(RequestStatus $from, RequestAction $action, array $roles): RequestStatus
    {
        if (!$this->allowsCancellationTransition($from, $action)) {
            throw new TransitionDenied('WF-003', $from, $action);
        }

        $target = match ([$from, $action]) {
            [RequestStatus::Registered, RequestAction::Reject],
            [RequestStatus::InProgress, RequestAction::Reject] => $this->forRoles(
                $roles,
                [Role::IcManager, Role::LaboratoryManager], RequestStatus::Rejected,
                'WF-006', $from, $action,
            ),
            [RequestStatus::Registered, RequestAction::Withdraw],
            [RequestStatus::InProgress, RequestAction::Withdraw],
            [RequestStatus::Suspended, RequestAction::Withdraw],
            [RequestStatus::OpinionPreparation, RequestAction::Withdraw] => RequestStatus::Withdrawn,
            default => RequestStatus::Withdrawn,
        };

        return $target;
    }

    public function allowsCancellationTransition(RequestStatus $from, RequestAction $action): bool
    {
        return match ($action) {
            RequestAction::Reject => in_array($from, [RequestStatus::Registered, RequestStatus::InProgress], true),
            RequestAction::Withdraw => in_array($from, [
                RequestStatus::Registered,
                RequestStatus::InProgress,
                RequestStatus::Suspended,
                RequestStatus::OpinionPreparation,
            ], true),
            default => false,
        };
    }

    /**
     * @param list<Role> $actual
     * @param list<Role> $allowed
     */
    private function forRoles(
        array $actual,
        array $allowed,
        RequestStatus $target,
        string $ruleId,
        RequestStatus $from,
        RequestAction $action,
    ): RequestStatus {
        foreach ($actual as $role) {
            if (in_array($role, $allowed, true)) {
                return $target;
            }
        }

        throw new TransitionDenied($ruleId, $from, $action);
    }
}
