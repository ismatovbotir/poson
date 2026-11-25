<?php

namespace App\Support\Telegram\MiniApp;

/**
 * Why an initData was refused (InitData::verify()). The reason is a short
 * code for tests and counters; it never carries any part of the input.
 */
final readonly class InvalidInitData
{
    public const TOO_LONG = 'too_long';

    public const MALFORMED = 'malformed';

    public const DUPLICATE = 'duplicate';

    public const MISSING = 'missing';

    public const SIGNATURE = 'signature';

    public const EXPIRED = 'expired';

    public const FUTURE = 'future';

    public const USER = 'user';

    public function __construct(public string $reason) {}

    /**
     * An old or future auth_date: the user can simply reopen the app.
     */
    public function isStale(): bool
    {
        return in_array($this->reason, [self::EXPIRED, self::FUTURE], true);
    }
}
