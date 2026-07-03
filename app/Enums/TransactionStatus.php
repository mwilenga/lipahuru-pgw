<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Received = 'RECEIVED';
    case Authenticated = 'AUTHENTICATED';
    case Validated = 'VALIDATED';
    case FundsReserved = 'FUNDS_RESERVED';
    case Acknowledged = 'ACKNOWLEDGED';
    case PendingFinal = 'PENDING_FINAL';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case Reversed = 'REVERSED';
    case Reconciling = 'RECONCILING';
    case Cancelled = 'CANCELLED';
}
