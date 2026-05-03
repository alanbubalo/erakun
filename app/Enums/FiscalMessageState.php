<?php

declare(strict_types=1);

namespace App\Enums;

enum FiscalMessageState: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Error = 'error';
}
