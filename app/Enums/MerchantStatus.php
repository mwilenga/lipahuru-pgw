<?php

namespace App\Enums;

enum MerchantStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Rejected = 'REJECTED';
}
