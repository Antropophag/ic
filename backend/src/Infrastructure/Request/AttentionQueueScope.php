<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Domain\Request\AttentionQueue;

/** Shared SQL predicates keep dashboard counts and registry items on one actionable scope. */
final class AttentionQueueScope
{
    public function condition(AttentionQueue $queue, string $actorParameter = ':attention_actor'): string
    {
        $active = "EXISTS(SELECT 1 FROM {{%users}} attention_user WHERE attention_user.id = {$actorParameter} AND attention_user.is_active = 1)";
        $manager = $this->hasRole($actorParameter, "'ic_manager', 'laboratory_manager'");
        $executor = $this->hasRole($actorParameter, "'ic_executor'");
        $expert = $this->hasRole($actorParameter, "'expert'");
        $security = $this->hasRole($actorParameter, "'security_officer'");
        $currentExecutor = "EXISTS(SELECT 1 FROM {{%request_assignments}} attention_executor WHERE attention_executor.request_id = r.id AND attention_executor.assignment_type = 'executor' AND attention_executor.valid_to IS NULL AND attention_executor.user_id = {$actorParameter})";
        $hasExecutor = "EXISTS(SELECT 1 FROM {{%request_assignments}} attention_any_executor WHERE attention_any_executor.request_id = r.id AND attention_any_executor.assignment_type = 'executor' AND attention_any_executor.valid_to IS NULL)";
        $currentExpert = "EXISTS(SELECT 1 FROM {{%request_assignments}} attention_expert WHERE attention_expert.request_id = r.id AND attention_expert.assignment_type = 'expert' AND attention_expert.valid_to IS NULL AND attention_expert.user_id = {$actorParameter})";
        $hasReport = "EXISTS(SELECT 1 FROM {{%request_documents}} attention_report WHERE attention_report.request_id = r.id AND attention_report.document_type = 'report' AND attention_report.deleted_at IS NULL)";

        $action = match ($queue) {
            AttentionQueue::AssignExecutor => "r.status = 'registered' AND {$manager} AND NOT ({$hasExecutor})",
            AttentionQueue::StartOrResumeWork => "((r.status = 'registered' AND {$hasExecutor}) OR r.status = 'suspended') AND ({$manager} OR ({$executor} AND {$currentExecutor}))",
            AttentionQueue::UploadReport => "r.status IN ('in_progress', 'opinion_preparation', 'completed') AND NOT ({$hasReport}) AND ({$manager} OR ({$executor} AND {$currentExecutor}))",
            AttentionQueue::ClaimExpert => "r.status = 'opinion_preparation' AND {$expert} AND NOT ({$currentExpert})",
            AttentionQueue::PublishOpinion => "r.status = 'opinion_preparation' AND {$currentExpert}",
            AttentionQueue::SecurityDecision => "r.status = 'security_review' AND {$security}",
        };

        return "({$active} AND {$action})";
    }

    private function hasRole(string $actorParameter, string $roleCodes): string
    {
        return "EXISTS(SELECT 1 FROM {{%user_roles}} attention_ur JOIN {{%roles}} attention_role ON attention_role.id = attention_ur.role_id WHERE attention_ur.user_id = {$actorParameter} AND attention_role.code IN ({$roleCodes}))";
    }
}
