<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum RequestColor: string
{
    case White = 'white';
    case Red = 'red';
    case Orange = 'orange';
    case Blue = 'blue';
    case Violet = 'violet';
    case Green = 'green';
}
