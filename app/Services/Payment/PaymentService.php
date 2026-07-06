<?php

namespace App\Services\Payment;

use App\Enums\GatewayErrorCode;
use App\Enums\MerchantStatus;
use App\Enums\PaymentOperation;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Exceptions\GatewayException;
use App\Models\Merchant;
use App\Models\ProviderNetwork;
use App\Models\Transaction;
use App\Providers\Payment\DTOs\CollectionRequest;
use App\Providers\Payment\DTOs\DisbursementRequest;
use App\Providers\Payment\ProviderRouter;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Wallet\WalletLedgerService;
use App\StateMachines\TransactionStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly TransactionStateMachine $stateMachine,
        private readonly WalletLedgerService $walletLedgerService,
        private readonly ProviderRouter $providerRouter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCollection(Merchant $merchant, array $payload): Transaction
    {
        $this->assertMerchantCanTransact($merchant);

        $providerNetwork = $this->resolveProviderNetwork($payload['providerCode']);
        $this->assertMerchantProfile($merchant, $providerNetwork, (string) $payload['amount']);

        return DB::transaction(function () use ($merchant, $payload, $providerNetwork): Transaction {
            $existing = $this->transactionRepository->findByMerchantAndRequestId(
                $merchant->id,
                (string) $payload['requestId'],
            );

            if ($existing !== null) {
                throw new GatewayException(GatewayErrorCode::DuplicateRequest, httpStatus: 409);
            }

            $transaction = $this->transactionRepository->create([
                'transaction_id' => $this->generateTransactionId(),
                'merchant_id' => $merchant->id,
                'provider_network_id' => $providerNetwork->id,
                'request_id' => $payload['requestId'],
                'reference' => $payload['reference'],
                'external_reference' => $payload['externalReference'] ?? null,
                'operation' => PaymentOperation::C2bPush,
                'status' => TransactionStatus::Received,
                'amount' => $payload['amount'],
                'currency' => $payload['currency'] ?? $merchant->default_currency,
                'msisdn' => $payload['msisdn'],
                'callback_url' => $payload['callbackUrl'] ?? $merchant->default_callback_url,
                'narration' => $payload['narration'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $transaction = $this->stateMachine->transition(
                $transaction,
                TransactionStatus::Authenticated,
                'AUTHENTICATED',
                actor: 'gateway',
            );

            $transaction = $this->stateMachine->transition(
                $transaction,
                TransactionStatus::Validated,
                'VALIDATED',
                actor: 'gateway',
            );

            $provider = $this->providerRouter->resolve($payload['providerCode'], PaymentOperation::C2bPush);

            $response = $provider->initiateCollection(new CollectionRequest(
                transactionId: $transaction->transaction_id,
                reference: $transaction->reference,
                amount: (string) $transaction->amount,
                currency: $transaction->currency,
                msisdn: $transaction->msisdn,
                providerCode: $payload['providerCode'],
                narration: $transaction->narration,
                callbackUrl: $transaction->callback_url,
                metadata: $transaction->metadata ?? [],
            ));

            $transaction->update([
                'payment_provider_id' => $providerNetwork->routes()->first()?->payment_provider_id,
                'provider_transaction_id' => $response->providerTransactionId,
                'provider_receipt_no' => $response->providerReceiptNo,
                'failure_code' => $response->failureCode,
                'failure_message' => $response->failureMessage,
            ]);

            $nextStatus = $response->status === TransactionStatus::PendingFinal
                ? TransactionStatus::PendingFinal
                : TransactionStatus::Acknowledged;

            return $this->stateMachine->transition(
                $transaction->refresh(),
                $nextStatus,
                'PROVIDER_ACKNOWLEDGED',
                payload: $response->rawResponse,
                actor: $provider->getDriverName(),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDisbursement(Merchant $merchant, array $payload): Transaction
    {
        $this->assertMerchantCanTransact($merchant);

        $providerNetwork = $this->resolveProviderNetwork($payload['providerCode']);
        $this->assertMerchantProfile($merchant, $providerNetwork, (string) $payload['amount']);

        return DB::transaction(function () use ($merchant, $payload, $providerNetwork): Transaction {
            $existing = $this->transactionRepository->findByMerchantAndRequestId(
                $merchant->id,
                (string) $payload['requestId'],
            );

            if ($existing !== null) {
                throw new GatewayException(GatewayErrorCode::DuplicateRequest, httpStatus: 409);
            }

            $disbursementWallet = $this->walletRepository->findByMerchantAndType(
                $merchant->id,
                WalletType::DisbursementLeaf,
                $providerNetwork->id,
            );

            if ($disbursementWallet === null) {
                throw new GatewayException(GatewayErrorCode::GeneralError, 'Disbursement wallet not provisioned', httpStatus: 422);
            }

            $transaction = $this->transactionRepository->create([
                'transaction_id' => $this->generateTransactionId(),
                'merchant_id' => $merchant->id,
                'provider_network_id' => $providerNetwork->id,
                'request_id' => $payload['requestId'],
                'reference' => $payload['reference'],
                'external_reference' => $payload['externalReference'] ?? null,
                'operation' => PaymentOperation::B2cDisbursement,
                'status' => TransactionStatus::Received,
                'amount' => $payload['amount'],
                'currency' => $payload['currency'] ?? $merchant->default_currency,
                'msisdn' => $payload['msisdn'],
                'callback_url' => $payload['callbackUrl'] ?? $merchant->default_callback_url,
                'narration' => $payload['narration'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $transaction = $this->stateMachine->transition($transaction, TransactionStatus::Authenticated, 'AUTHENTICATED', actor: 'gateway');
            $transaction = $this->stateMachine->transition($transaction, TransactionStatus::Validated, 'VALIDATED', actor: 'gateway');

            $this->walletLedgerService->reserveFunds($disbursementWallet, $transaction, (string) $transaction->amount);

            $transaction = $this->stateMachine->transition($transaction, TransactionStatus::FundsReserved, 'FUNDS_RESERVED', actor: 'gateway');

            $provider = $this->providerRouter->resolve($payload['providerCode'], PaymentOperation::B2cDisbursement);

            try {
                $response = $provider->initiateDisbursement(new DisbursementRequest(
                    transactionId: $transaction->transaction_id,
                    reference: $transaction->reference,
                    amount: (string) $transaction->amount,
                    currency: $transaction->currency,
                    msisdn: $transaction->msisdn,
                    providerCode: $payload['providerCode'],
                    narration: $transaction->narration,
                    callbackUrl: $transaction->callback_url,
                    metadata: $transaction->metadata ?? [],
                ));
            } catch (\Throwable $exception) {
                $this->walletLedgerService->releaseFunds($transaction);
                $this->stateMachine->transition(
                    $transaction->refresh(),
                    TransactionStatus::Failed,
                    'PROVIDER_SUBMISSION_FAILED',
                    payload: ['message' => $exception->getMessage()],
                    actor: 'gateway',
                    attributes: [
                        'failure_code' => GatewayErrorCode::GeneralError->value,
                        'failure_message' => $exception->getMessage(),
                    ],
                );

                throw $exception instanceof GatewayException
                    ? $exception
                    : new GatewayException(GatewayErrorCode::GeneralError, $exception->getMessage(), httpStatus: 502);
            }

            if (! $response->success) {
                $this->walletLedgerService->releaseFunds($transaction);

                return $this->stateMachine->transition(
                    $transaction->refresh(),
                    TransactionStatus::Failed,
                    'PROVIDER_REJECTED',
                    payload: $response->rawResponse,
                    actor: $provider->getDriverName(),
                    attributes: [
                        'failure_code' => $response->failureCode,
                        'failure_message' => $response->failureMessage,
                    ],
                );
            }

            $transaction->update([
                'payment_provider_id' => $providerNetwork->routes()->first()?->payment_provider_id,
                'provider_transaction_id' => $response->providerTransactionId,
                'provider_receipt_no' => $response->providerReceiptNo,
            ]);

            $nextStatus = $response->status === TransactionStatus::PendingFinal
                ? TransactionStatus::PendingFinal
                : TransactionStatus::Acknowledged;

            return $this->stateMachine->transition(
                $transaction->refresh(),
                $nextStatus,
                'PROVIDER_ACKNOWLEDGED',
                payload: $response->rawResponse,
                actor: $provider->getDriverName(),
            );
        });
    }

    public function finalizeSuccess(Transaction $transaction): Transaction
    {
        if ($transaction->status === TransactionStatus::Acknowledged) {
            $transaction = $this->stateMachine->transition(
                $transaction,
                TransactionStatus::PendingFinal,
                'PENDING_FINAL',
                actor: 'gateway',
            );
        }

        $transaction = $this->stateMachine->transition(
            $transaction,
            TransactionStatus::Success,
            'PAYMENT_SUCCEEDED',
            actor: 'gateway',
        );

        if ($transaction->operation === PaymentOperation::B2cDisbursement) {
            $this->walletLedgerService->consumeFunds($transaction);
        }

        if ($transaction->operation === PaymentOperation::C2bPush && $transaction->provider_network_id !== null) {
            $collectionWallet = $this->walletRepository->findByMerchantAndType(
                $transaction->merchant_id,
                WalletType::CollectionLeaf,
                $transaction->provider_network_id,
            );

            if ($collectionWallet !== null) {
                $this->walletLedgerService->creditCollectionWallet(
                    $collectionWallet,
                    $transaction,
                    (string) $transaction->amount,
                );
            }
        }

        return $transaction->refresh();
    }

    public function finalizeFailure(Transaction $transaction, ?string $failureCode = null, ?string $failureMessage = null): Transaction
    {
        if ($transaction->operation === PaymentOperation::B2cDisbursement) {
            $this->walletLedgerService->releaseFunds($transaction);
        }

        return $this->stateMachine->transition(
            $transaction,
            TransactionStatus::Failed,
            'PAYMENT_FAILED',
            actor: 'gateway',
            attributes: array_filter([
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
            ]),
        );
    }

    private function assertMerchantCanTransact(Merchant $merchant): void
    {
        if ($merchant->status !== MerchantStatus::Active) {
            throw new GatewayException(GatewayErrorCode::AuthenticationFailed, 'Merchant is not active', httpStatus: 403);
        }
    }

    private function resolveProviderNetwork(string $providerCode): ProviderNetwork
    {
        $network = ProviderNetwork::query()
            ->where('code', $providerCode)
            ->where('is_active', true)
            ->first();

        if ($network === null) {
            throw new GatewayException(GatewayErrorCode::UnsupportedProvider);
        }

        return $network;
    }

    private function assertMerchantProfile(Merchant $merchant, ProviderNetwork $network, string $amount): void
    {
        $profile = $merchant->providerProfiles()
            ->where('provider_network_id', $network->id)
            ->where('is_enabled', true)
            ->first();

        if ($profile === null) {
            throw new GatewayException(GatewayErrorCode::UnsupportedProvider, 'Provider not enabled for merchant');
        }

        if (bccomp($amount, (string) $profile->min_amount, 4) < 0 || bccomp($amount, (string) $profile->max_amount, 4) > 0) {
            throw new GatewayException(GatewayErrorCode::AmountLimitExceeded);
        }
    }

    private function generateTransactionId(): string
    {
        $prefix = config('payment-gateway.transaction_id_prefix', 'TXN');

        return $prefix.'-'.strtoupper(Str::random(16));
    }
}
