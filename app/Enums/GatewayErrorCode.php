<?php

namespace App\Enums;

enum GatewayErrorCode: string
{
    case Success = 'PGW-0000';
    case InvalidPayload = 'PGW-1001';
    case UnsupportedProvider = 'PGW-1002';
    case InvalidMsisdn = 'PGW-1003';
    case DuplicateRequest = 'PGW-1004';
    case AmountLimitExceeded = 'PGW-1005';
    case InsufficientBalance = 'PGW-1006';
    case AuthenticationFailed = 'PGW-1007';
    case SignatureFailed = 'PGW-1008';
    case ReplayProtectionFailed = 'PGW-1009';
    case TransactionNotFound = 'PGW-1010';
    case GeneralError = 'PGW-1099';

    public function message(): string
    {
        return match ($this) {
            self::Success => 'Request processed successfully',
            self::InvalidPayload => 'Invalid request payload',
            self::UnsupportedProvider => 'Unsupported or disabled providerCode for the merchant',
            self::InvalidMsisdn => 'Invalid MSISDN format',
            self::DuplicateRequest => 'Duplicate requestId or idempotency conflict',
            self::AmountLimitExceeded => 'Amount exceeds configured merchant limits',
            self::InsufficientBalance => 'Insufficient available balance in disbursement wallet',
            self::AuthenticationFailed => 'Authentication failed',
            self::SignatureFailed => 'Signature validation failed',
            self::ReplayProtectionFailed => 'Replay protection failed due to nonce or timestamp validation',
            self::TransactionNotFound => 'Transaction not found',
            self::GeneralError => 'General processing error',
        };
    }
}
