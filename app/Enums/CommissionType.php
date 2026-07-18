<?php

namespace App\Enums;

enum CommissionType: string
{
    case Fixed = 'FIXED';
    case Percent = 'PERCENT';
}
