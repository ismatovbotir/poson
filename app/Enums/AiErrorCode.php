<?php

namespace App\Enums;

/**
 * Safe failure code of an AI request (ai_requests.error_code). The only
 * thing stored or shown about a failure: provider messages and exceptions
 * are never logged (they may echo keys or prompts). Messages: lang ai.errors.*.
 */
enum AiErrorCode: string
{
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case ProviderError = 'provider_error';
    case InvalidOutput = 'invalid_output';
    case NotConfigured = 'not_configured';
    case Disabled = 'disabled';
    case QuotaExceeded = 'quota_exceeded';
    case BudgetExceeded = 'budget_exceeded';

    /**
     * Translated message for the assistant panel.
     */
    public function message(): string
    {
        return __("ai.errors.{$this->value}");
    }

    /**
     * Short label for the usage screen.
     */
    public function label(): string
    {
        return __("enums.ai_error_code.{$this->value}");
    }

    /**
     * Retrying the same request may help (shows the Retry button).
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Timeout, self::RateLimited, self::ProviderError, self::InvalidOutput], true);
    }
}
