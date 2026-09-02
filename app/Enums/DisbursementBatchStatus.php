<?php

namespace App\Enums;

enum DisbursementBatchStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case PartiallyCompleted = 'PARTIALLY_COMPLETED';
    case Failed = 'FAILED';
}
