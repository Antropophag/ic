<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

final class RequestAgingSql
{
    public static function selects(bool $includeStateReason = false): string
    {
        $sql = "DATE_FORMAT(GREATEST(r.updated_at, COALESCE((SELECT MAX(change_audit.created_at) FROM {{%audit_events}} change_audit WHERE change_audit.entity_type = 'request' AND change_audit.entity_id = r.id AND change_audit.event_type IN ('request.created', 'request.imported', 'request.department_changed', 'request.color_marked', 'request.comment_added', 'request.document_uploaded', 'request.executor_assigned', 'request.expert_claimed', 'request.expert_reassigned', 'request.started', 'request.suspended', 'request.resumed', 'request.report_uploaded', 'request.report_deleted', 'request.opinion_published', 'request.security_decided', 'request.rejected', 'request.withdrawn')), r.updated_at)), '%Y-%m-%dT%H:%i:%s.%fZ') AS last_changed_at, "
            . "DATE_FORMAT(COALESCE((SELECT state_transition.created_at FROM {{%request_transitions}} state_transition WHERE state_transition.request_id = r.id AND state_transition.to_status = r.status AND (state_transition.from_status IS NULL OR state_transition.from_status <> state_transition.to_status) ORDER BY state_transition.created_at DESC, state_transition.id DESC LIMIT 1), r.created_at), '%Y-%m-%dT%H:%i:%s.%fZ') AS state_changed_at, ";
        if (!$includeStateReason) {
            return $sql;
        }

        return $sql . "CASE WHEN r.status IN ('suspended', 'rejected', 'withdrawn') THEN (SELECT status_transition.reason FROM {{%request_transitions}} status_transition WHERE status_transition.request_id = r.id AND status_transition.to_status = r.status AND status_transition.action = CASE r.status WHEN 'suspended' THEN 'suspend' WHEN 'rejected' THEN 'reject' ELSE 'withdraw' END ORDER BY status_transition.created_at DESC, status_transition.id DESC LIMIT 1) ELSE NULL END AS status_reason, ";
    }
}
