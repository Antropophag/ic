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
    case SecurityDecline = 'security_decline';
    case CloseRequest = 'close_request';
    case SubmitForExpertise = 'submit_for_expertise';
    case Reject = 'reject';
    case Withdraw = 'withdraw';
}
