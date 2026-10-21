<?php

namespace App\Support\Integrations;

/**
 * Outcome of a "Test connection" (Settings → Integrations). "code" is a safe
 * error code with a lang key (admin.settings.integrations.test.codes.*);
 * "details" holds display-safe values only (bot username, model count,
 * credits) — never a token, key or URL.
 */
final readonly class ConnectionTestResult
{
    public const OK = 'ok';

    /**
     * @param  array<string, string|int>  $details
     */
    public function __construct(
        public bool $ok,
        public string $code,
        public array $details = [],
    ) {}

    /**
     * @param  array<string, string|int>  $details
     */
    public static function success(string $code = self::OK, array $details = []): self
    {
        return new self(true, $code, $details);
    }

    /**
     * @param  array<string, string|int>  $details
     */
    public static function failure(string $code, array $details = []): self
    {
        return new self(false, $code, $details);
    }

    /**
     * Localized message for the admin screen.
     */
    public function message(): string
    {
        return __('admin.settings.integrations.test.codes.'.$this->code, $this->details);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message()];
    }
}
