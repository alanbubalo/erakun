<?php

declare(strict_types=1);

namespace App\Enums;

enum VatCategory: string
{
    case Standard = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';
}
