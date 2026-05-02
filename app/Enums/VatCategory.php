<?php

namespace App\Enums;

enum VatCategory: string
{
    case Standard = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';
}
