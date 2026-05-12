<?php

declare(strict_types=1);

namespace App\Enums;

enum As4MessageState: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case Received = 'received';
    case Delivered = 'delivered';
    case Error = 'error';
}
