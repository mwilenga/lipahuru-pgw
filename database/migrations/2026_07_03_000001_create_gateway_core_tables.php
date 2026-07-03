<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('environment', 16)->default('uat');
            $table->string('default_currency', 3)->default('TZS');
            $table->string('default_callback_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('merchant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role', 32)->default('owner');
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['merchant_id', 'email']);
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 32)->default('admin');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('merchant_api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 64)->unique();
            $table->string('client_secret_hash');
            $table->string('name')->default('default');
            $table->string('status', 32)->default('ACTIVE');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->text('signing_secret');
            $table->text('callback_secret');
            $table->text('previous_signing_secret')->nullable();
            $table->text('previous_callback_secret')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('rotation_grace_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('driver', 64);
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_networks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('provider_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_provider_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 32);
            $table->unsignedTinyInteger('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_healthy')->default(true);
            $table->timestamps();
            $table->unique(['provider_network_id', 'operation', 'priority'], 'provider_routes_unique');
        });

        Schema::create('merchant_provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_network_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('min_amount', 18, 4)->default(100);
            $table->decimal('max_amount', 18, 4)->default(10000000);
            $table->decimal('daily_limit', 18, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'provider_network_id'], 'mpp_merchant_network_uniq');
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('provider_network_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wallet_type', 32);
            $table->string('currency', 3)->default('TZS');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['merchant_id', 'wallet_type']);
        });

        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('available', 18, 4)->default(0);
            $table->decimal('reserved', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 64)->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_network_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id');
            $table->string('reference');
            $table->string('external_reference')->nullable();
            $table->string('operation', 32);
            $table->string('status', 32)->default('RECEIVED');
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->default('TZS');
            $table->string('msisdn', 20);
            $table->string('callback_url')->nullable();
            $table->string('narration')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_receipt_no')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'reference']);
            $table->index(['merchant_id', 'request_id']);
            $table->index(['status', 'created_at']);
            $table->index('provider_transaction_id');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_type', 32);
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->default('TZS');
            $table->decimal('balance_after', 18, 4);
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('transaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('event_type', 64);
            $table->json('payload')->nullable();
            $table->string('actor', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['transaction_id', 'created_at']);
        });

        Schema::create('balance_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->default('TZS');
            $table->string('status', 32)->default('ACTIVE');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('http_status');
            $table->json('response_body');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'idempotency_key']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_id', 64)->unique();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->default('TZS');
            $table->string('status', 32)->default('PENDING');
            $table->string('reason')->nullable();
            $table->string('provider_refund_id')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_id', 64)->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->date('settlement_date');
            $table->string('status', 32)->default('PENDING');
            $table->decimal('gross_amount', 18, 4)->default(0);
            $table->decimal('fee_amount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'settlement_date']);
        });

        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('fee_amount', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['settlement_id', 'transaction_id']);
        });

        Schema::create('merchant_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('events')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('callback_id')->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('url');
            $table->json('payload');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('max_attempts')->default(10);
            $table->string('status', 32)->default('PENDING');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_retry_at']);
        });

        Schema::create('incoming_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code', 32);
            $table->string('event_type', 64)->nullable();
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('RECEIVED');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nonce_records', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64);
            $table->string('nonce', 128);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['client_id', 'nonce']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 128);
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('daily_merchant_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('successful_transactions')->default(0);
            $table->unsignedInteger('failed_transactions')->default(0);
            $table->decimal('total_volume', 18, 4)->default(0);
            $table->decimal('successful_volume', 18, 4)->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->timestamps();
            $table->unique(['merchant_id', 'summary_date']);
        });

        Schema::create('provider_performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_provider_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedInteger('total_requests')->default(0);
            $table->unsignedInteger('successful_requests')->default(0);
            $table->unsignedInteger('failed_requests')->default(0);
            $table->unsignedInteger('avg_latency_ms')->default(0);
            $table->timestamps();
            $table->unique(['payment_provider_id', 'metric_date'], 'ppm_provider_date_uniq');
        });
    }

    public function down(): void
    {
        $tables = [
            'provider_performance_metrics',
            'daily_merchant_summaries',
            'audit_logs',
            'nonce_records',
            'incoming_webhook_logs',
            'webhook_deliveries',
            'merchant_webhooks',
            'settlement_items',
            'settlements',
            'refunds',
            'idempotency_records',
            'balance_reservations',
            'transaction_events',
            'transactions',
            'ledger_entries',
            'wallet_balances',
            'wallets',
            'merchant_provider_profiles',
            'provider_routes',
            'provider_networks',
            'payment_providers',
            'api_credentials',
            'merchant_api_clients',
            'admin_users',
            'merchant_users',
            'merchants',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
