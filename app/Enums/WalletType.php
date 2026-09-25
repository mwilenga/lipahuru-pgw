<?php

namespace App\Enums;

enum WalletType: string
{
    case MerchantParent = 'MERCHANT_PARENT';
    case ProviderTotal = 'PROVIDER_TOTAL';
    case CollectionLeaf = 'COLLECTION_LEAF';
    case DisbursementLeaf = 'DISBURSEMENT_LEAF';
    /** Legacy single-wallet mode (collapsed); kept for restore tooling */
    case MerchantBalance = 'MERCHANT_BALANCE';
}
