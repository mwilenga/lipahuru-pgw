<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_id', 64)->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('batch_reference')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('currency', 3)->default('TZS');
            $table->decimal('total_amount', 18, 4);
            $table->unsignedInteger('total_items');
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('callback_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('disbursement_batch_id')
                ->nullable()
                ->after('payment_provider_id')
                ->constrained('disbursement_batches')
                ->nullOnDelete();

            $table->index('disbursement_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('disbursement_batch_id');
        });

        Schema::dropIfExists('disbursement_batches');
    }
};
