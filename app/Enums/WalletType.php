<?php

namespace App\Enums;

enum WalletType: string
{
    case MerchantBalance = 'MERCHANT_BALANCE';
    /** @deprecated Historical hierarchy; inactive after collapse migration. */
    case MerchantParent = 'MERCHANT_PARENT';
    /** @deprecated Historical hierarchy; inactive after collapse migration. */
    case ProviderTotal = 'PROVIDER_TOTAL';
    /** @deprecated Historical hierarchy; inactive after collapse migration. */
    case CollectionLeaf = 'COLLECTION_LEAF';
    /** @deprecated Historical hierarchy; inactive after collapse migration. */
    case DisbursementLeaf = 'DISBURSEMENT_LEAF';
}
