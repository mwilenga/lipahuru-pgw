<?php

use App\Enums\CommissionType;
use App\Enums\PaymentOperation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * B2C rows saved as PERCENT with value > 100 were capped to 100% in fee math,
     * making charges equal gross. Those values were intended as FIXED TZS fees.
     */
    public function up(): void
    {
        DB::table('merchant_commissions')
            ->where('operation', PaymentOperation::B2cDisbursement->value)
            ->where('commission_type', CommissionType::Percent->value)
            ->where('value', '>', 100)
            ->update(['commission_type' => CommissionType::Fixed->value]);
    }

    public function down(): void
    {
        // Irreversible: original PERCENT intent for corrected rows is unknown.
    }
};
