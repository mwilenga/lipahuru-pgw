<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->string('settlement_bank_name')->nullable()->after('default_callback_url');
            $table->string('settlement_account_name')->nullable()->after('settlement_bank_name');
            $table->string('settlement_account_number', 64)->nullable()->after('settlement_account_name');
            $table->string('settlement_bank_branch')->nullable()->after('settlement_account_number');
        });

        Schema::create('settlement_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('commission_amount', 18, 4)->default(0);
            $table->decimal('total_debit', 18, 4);
            $table->string('currency', 3)->default('TZS');
            $table->string('status', 32)->default('PENDING_APPROVAL');
            $table->text('memo')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('bank_branch')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('merchant_users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['wallet_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_requests');

        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropColumn([
                'settlement_bank_name',
                'settlement_account_name',
                'settlement_account_number',
                'settlement_bank_branch',
            ]);
        });
    }
};
