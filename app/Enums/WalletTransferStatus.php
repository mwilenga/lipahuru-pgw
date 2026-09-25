<?php

namespace App\Enums;

enum WalletTransferStatus: string
{
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
