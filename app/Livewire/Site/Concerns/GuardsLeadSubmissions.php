<?php

namespace App\Livewire\Site\Concerns;

use App\Livewire\Site\LeadForm;
use App\Support\Analytics\Attribution;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spam checks shared by the site's lead forms (Site\LeadForm and the cart
 * page's "Request a quote for this cart"): the same per-IP and per-phone
 * rate limits (one budget for both forms), the honeypot "fax_ext" and the
 * minimum fill time from "renderedAt", and the session's first-touch
 * attribution (never failing the form).
 *
 * The component declares the public "fax_ext" and #[Locked] "renderedAt".
 */
trait GuardsLeadSubmissions
{
    /**
     * Count a valid submission against the IP and phone budgets; true (with a
     * form error) when either is exceeded.
     */
    protected function leadLimitsExceeded(string $phone): bool
    {
        // Count first, then compare: concurrent requests can't slip past the limit.
        return $this->limitExceeded('site-lead:'.sha1((string) request()->ip()), LeadForm::RATE_LIMIT, LeadForm::RATE_LIMIT_SECONDS)
            || $this->limitExceeded('site-lead-phone:'.sha1($phone), LeadForm::PHONE_LIMIT, LeadForm::PHONE_LIMIT_SECONDS);
    }

    /**
     * Register a hit and report (as a form error) whether the limit is now exceeded.
     */
    protected function limitExceeded(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if (RateLimiter::hit($key, $decaySeconds) <= $maxAttempts) {
            return false;
        }

        $this->addError('form', __('site.lead_form.error_rate_limited', [
            'seconds' => RateLimiter::availableIn($key),
        ]));

        return true;
    }

    protected function looksLikeBot(): bool
    {
        return $this->fax_ext !== ''
            || now()->getTimestamp() - $this->renderedAt < LeadForm::MIN_FILL_SECONDS;
    }

    /**
     * The session's first-touch attribution; empty if analytics fails (the form must always work).
     *
     * @return array<string, string>
     */
    protected function captureAttribution(): array
    {
        try {
            return app(Attribution::class)->current(request());
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
