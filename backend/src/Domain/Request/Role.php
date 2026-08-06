<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum Role: string
{
    case Employee = 'employee';
    case IcExecutor = 'ic_executor';
    case Expert = 'expert';
    case IcManager = 'ic_manager';
    case LaboratoryManager = 'laboratory_manager';
    case SecurityOfficer = 'security_officer';
    case Administrator = 'administrator';
}
