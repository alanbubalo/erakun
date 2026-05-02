<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
