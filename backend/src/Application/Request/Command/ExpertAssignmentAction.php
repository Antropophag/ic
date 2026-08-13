<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

enum ExpertAssignmentAction
{
    case Claim;
    case Reassign;
}
