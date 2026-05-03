<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Mismatch = 'mismatch';
}
