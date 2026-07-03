<?php

namespace App\Enums;

enum PaymentOperation: string
{
    case C2bPush = 'C2B_USSD_PUSH';
    case B2cDisbursement = 'B2C_DISBURSEMENT';
    case Refund = 'REFUND';
}
