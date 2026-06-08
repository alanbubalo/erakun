<?php

declare(strict_types=1);

namespace App\Enums;

enum CertificateStatus: string
{
    case Active = 'active';       // the one certificate currently used to sign
    case Superseded = 'superseded'; // replaced by a newer upload
    case Revoked = 'revoked';     // explicitly withdrawn
}
