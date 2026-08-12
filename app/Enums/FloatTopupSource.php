<?php

namespace App\Enums;

enum FloatTopupSource: string
{
    case Merchant = 'MERCHANT';
    case Admin = 'ADMIN';
}
