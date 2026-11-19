<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_fee_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_network_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 32);
            $table->string('commission_type', 16);
            $table->decimal('value', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['provider_network_id', 'operation', 'is_active']);
            $table->index(['operation', 'is_active']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->decimal('merchant_fee_amount', 18, 4)->nullable()->after('amount');
            $table->decimal('provider_fee_amount', 18, 4)->nullable()->after('merchant_fee_amount');
            $table->foreignId('provider_fee_rate_id')
                ->nullable()
                ->after('provider_fee_amount')
                ->constrained('provider_fee_rates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_fee_rate_id');
            $table->dropColumn(['merchant_fee_amount', 'provider_fee_amount']);
        });

        Schema::dropIfExists('provider_fee_rates');
    }
};
