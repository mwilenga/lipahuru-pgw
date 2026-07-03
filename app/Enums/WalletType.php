<?php

namespace App\Enums;

enum WalletType: string
{
    case MerchantParent = 'MERCHANT_PARENT';
    case ProviderTotal = 'PROVIDER_TOTAL';
    case CollectionLeaf = 'COLLECTION_LEAF';
    case DisbursementLeaf = 'DISBURSEMENT_LEAF';
}
