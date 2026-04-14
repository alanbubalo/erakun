<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
}
