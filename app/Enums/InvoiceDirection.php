<?php

namespace App\Enums;

enum InvoiceDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
