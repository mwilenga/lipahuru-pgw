<?php

namespace App\Enums;

enum WalletTransferSource: string
{
    case Merchant = 'MERCHANT';
    case Admin = 'ADMIN';
}
