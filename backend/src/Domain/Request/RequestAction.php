<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum RequestAction: string
{
    case Start = 'start';
    case Suspend = 'suspend';
    case Resume = 'resume';
    case UploadReport = 'upload_report';
    case PublishOpinion = 'publish_opinion';
    case SecurityApprove = 'security_approve';
    case SecurityReturn = 'security_return';
    case Reject = 'reject';
    case Withdraw = 'withdraw';
}
