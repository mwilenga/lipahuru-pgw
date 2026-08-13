<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('float_topups', function (Blueprint $table): void {
            $table->id();
            $table->string('topup_id', 64)->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('status', 16)->default('PENDING');
            $table->string('currency', 3)->default('TZS');
            $table->decimal('total_amount', 18, 4);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('requested_by_type', 32)->nullable();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('float_topup_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('float_topup_id')->constrained('float_topups')->cascadeOnDelete();
            $table->foreignId('provider_network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamps();

            $table->unique(['float_topup_id', 'provider_network_id'], 'float_topup_items_network_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('float_topup_items');
        Schema::dropIfExists('float_topups');
    }
};
