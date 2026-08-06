<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class AttachmentDenied extends \DomainException
{
    public function __construct(public readonly string $ruleId = 'COM-004')
    {
        parent::__construct('Attachments are not allowed for the current request state.');
    }
}
