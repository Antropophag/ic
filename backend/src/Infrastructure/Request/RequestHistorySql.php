<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

final class RequestHistorySql
{
    public static function missingCreation(): string
    {
        return "UNION ALL SELECT creation_request.id, 'request' AS kind, 'create' AS action, NULL, 'registered', "
            . "'REQ-007', NULL, DATE_FORMAT(creation_request.created_at, '%Y-%m-%dT%H:%i:%s.%fZ'), creation_actor.display_name, NULL, NULL, NULL "
            . 'FROM {{%requests}} creation_request JOIN {{%users}} creation_actor ON creation_actor.id = creation_request.initiator_id '
            . 'WHERE creation_request.id = :creation_request_id AND NOT EXISTS (SELECT 1 FROM {{%request_transitions}} creation_transition '
            . "WHERE creation_transition.request_id = creation_request.id AND creation_transition.action IN ('create', 'import')) ";
    }
}
