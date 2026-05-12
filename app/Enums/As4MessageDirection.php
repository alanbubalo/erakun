<?php

declare(strict_types=1);

namespace App\Enums;

enum As4MessageDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
