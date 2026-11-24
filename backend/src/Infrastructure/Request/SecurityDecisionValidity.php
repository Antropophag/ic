<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

/** Deleted opinions invalidate their decisions, while retaining immutable history and imported decisions. */
final class SecurityDecisionValidity
{
    public static function condition(string $alias): string
    {
        return 'NOT EXISTS(SELECT 1 FROM {{%expert_opinions}} validity_opinion '
            . 'JOIN {{%request_document_versions}} validity_version ON validity_version.id = validity_opinion.document_version_id '
            . 'JOIN {{%request_documents}} validity_document ON validity_document.id = validity_version.document_id '
            . "WHERE validity_opinion.id = {$alias}.expert_opinion_id "
            . 'AND (validity_version.deleted_at IS NOT NULL OR validity_document.deleted_at IS NOT NULL))';
    }
}
