<?php

namespace App\Enums;

enum SettlementRequestStatus: string
{
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    /**
     * Statuses whose commission has been taken (or is held) and must not be charged again.
     *
     * @return list<string>
     */
    public static function commissionClaimingValues(): array
    {
        return [self::PendingApproval->value, self::Approved->value];
    }
}
