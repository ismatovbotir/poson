<?php

namespace App\Services\Payments\Uzqr;

use RuntimeException;

/**
 * A failed UzQR call (security conditions 48, 49): carries only the
 * endpoint, the HTTP status, the bank's numeric error_code and our own code
 * ("reason") — never the response body, the bank's message texts, the Auth
 * header or the request URL. Never thrown to the reporter or the browser:
 * callers turn it into a state change and their own localized message
 * (lang uzqr.errors.*).
 */
class UzqrException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const AUTH_FAILED = 'auth_failed';

    public const BLOCKED = 'blocked';

    public const INVALID_REQUEST = 'invalid_request';

    public const INVALID_AMOUNT = 'invalid_amount';

    public const NOT_FOUND = 'not_found';

    public const ALREADY_PAID = 'already_paid';

    public const RATE_LIMITED = 'rate_limited';

    public const SERVER_ERROR = 'server_error';

    public const CONNECTION_FAILED = 'connection_failed';

    public const TIMEOUT = 'timeout';

    public const INVALID_RESPONSE = 'invalid_response';

    public const MISSING_PAY_ID = 'missing_pay_id';

    public const BUSY = 'busy';

    public const UNEXPECTED = 'unexpected';

    /**
     * Codes worth another try later (the bank or the network may recover).
     */
    public const TRANSIENT = [
        self::RATE_LIMITED, self::SERVER_ERROR, self::CONNECTION_FAILED, self::TIMEOUT,
        self::INVALID_RESPONSE, self::MISSING_PAY_ID, self::BUSY, self::UNEXPECTED,
    ];

    public function __construct(
        public readonly string $reason,
        public readonly string $endpoint = '',
        public readonly int $httpStatus = 0,
        public readonly ?int $errorCode = null,
    ) {
        parent::__construct("UzQR {$endpoint} failed: {$reason} (HTTP {$httpStatus}".($errorCode !== null ? ", error {$errorCode}" : '').')');
    }

    /**
     * Our code for a bank error response (error_code first, else the HTTP class).
     */
    public static function fromResponse(string $endpoint, int $httpStatus, ?int $errorCode): self
    {
        $reason = match (true) {
            $errorCode === 1001 => self::AUTH_FAILED,
            $errorCode === 1002 => self::BLOCKED,
            $errorCode === 2002 => self::INVALID_AMOUNT,
            in_array($errorCode, [2001, 2003, 2004], true) => self::INVALID_REQUEST,
            $errorCode === 3001 => self::NOT_FOUND,
            $errorCode === 3002 => self::ALREADY_PAID,
            $errorCode === 6001 => self::RATE_LIMITED,
            $errorCode !== null && $errorCode >= 9001 && $errorCode <= 9004 => self::SERVER_ERROR,
            $httpStatus === 401 => self::AUTH_FAILED,
            $httpStatus === 403 => self::BLOCKED,
            $httpStatus === 429 => self::RATE_LIMITED,
            $httpStatus >= 500 => self::SERVER_ERROR,
            $httpStatus === 400 => self::INVALID_REQUEST,
            default => self::UNEXPECTED,
        };

        return new self($reason, $endpoint, $httpStatus, $errorCode);
    }

    public function isAuth(): bool
    {
        return in_array($this->reason, [self::AUTH_FAILED, self::BLOCKED], true);
    }

    public function isTransient(): bool
    {
        return in_array($this->reason, self::TRANSIENT, true);
    }

    public function isPermanent(): bool
    {
        return ! $this->isTransient();
    }

    /**
     * Worth an in-request retry of invoice/create with the same external_id.
     */
    public function isRetryable(): bool
    {
        return in_array($this->reason, [self::SERVER_ERROR, self::CONNECTION_FAILED, self::TIMEOUT], true);
    }
}
