<?php

namespace App\StateMachines;

use App\Enums\GatewayErrorCode;
use App\Enums\TransactionStatus;
use App\Exceptions\GatewayException;
use App\Models\Transaction;
use App\Models\TransactionEvent;

class TransactionStateMachine
{
    /** @var array<string, list<TransactionStatus>> */
    private const TRANSITIONS = [
        'RECEIVED' => [
            TransactionStatus::Authenticated,
            TransactionStatus::Failed,
            TransactionStatus::Cancelled,
        ],
        'AUTHENTICATED' => [
            TransactionStatus::Validated,
            TransactionStatus::Failed,
            TransactionStatus::Cancelled,
        ],
        'VALIDATED' => [
            TransactionStatus::FundsReserved,
            TransactionStatus::Acknowledged,
            TransactionStatus::Failed,
            TransactionStatus::Cancelled,
        ],
        'FUNDS_RESERVED' => [
            TransactionStatus::Acknowledged,
            TransactionStatus::Failed,
            TransactionStatus::Cancelled,
        ],
        'ACKNOWLEDGED' => [
            TransactionStatus::PendingFinal,
            TransactionStatus::Failed,
        ],
        'PENDING_FINAL' => [
            TransactionStatus::Success,
            TransactionStatus::Failed,
            TransactionStatus::Reversed,
            TransactionStatus::Reconciling,
        ],
        'RECONCILING' => [
            TransactionStatus::Success,
            TransactionStatus::Failed,
            TransactionStatus::Reversed,
        ],
    ];

    public function transition(
        Transaction $transaction,
        TransactionStatus $toStatus,
        string $eventType,
        ?array $payload = null,
        ?string $actor = null,
        ?array $attributes = [],
    ): Transaction {
        $fromStatus = $transaction->status;

        if ($fromStatus === $toStatus) {
            return $transaction;
        }

        if (! $this->canTransition($fromStatus, $toStatus)) {
            throw new GatewayException(
                GatewayErrorCode::GeneralError,
                "Invalid transaction transition from {$fromStatus->value} to {$toStatus->value}",
                httpStatus: 422,
            );
        }

        $update = array_merge($attributes, ['status' => $toStatus]);

        if ($this->isTerminal($toStatus)) {
            $update['finalized_at'] = now();
        }

        $transaction->update($update);

        TransactionEvent::query()->create([
            'transaction_id' => $transaction->id,
            'from_status' => $fromStatus->value,
            'to_status' => $toStatus->value,
            'event_type' => $eventType,
            'payload' => $payload,
            'actor' => $actor,
            'created_at' => now(),
        ]);

        return $transaction->refresh();
    }

    public function canTransition(TransactionStatus $from, TransactionStatus $to): bool
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }

    private function isTerminal(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::Success,
            TransactionStatus::Failed,
            TransactionStatus::Reversed,
            TransactionStatus::Cancelled,
        ], true);
    }
}
