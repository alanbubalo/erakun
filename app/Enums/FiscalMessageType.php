<?php

declare(strict_types=1);

namespace App\Enums;

enum FiscalMessageType: string
{
    case Fis = 'fis';

    // Reserved for later phases:
    // case FisPayment = 'fis_payment';
    // case FisReject  = 'fis_reject';
    // case FisIr      = 'fis_ir';
}
