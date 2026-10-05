<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum RequestRoute: string
{
    case Act = 'act';
    case Protocol = 'protocol';
}
