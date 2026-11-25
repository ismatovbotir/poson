<?php

namespace App\Livewire\Site\Concerns;

use App\Livewire\Site\LeadForm;
use App\Support\Analytics\AdClickIds;
use App\Support\Analytics\Attribution;
use App\Support\ForeignPhoneLimit;
use App\Support\Leads\LeadLimits;

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
        // The keys are LeadLimits' (shared with the Telegram Mini App, plan 20 MA-12).
        return $this->limitExceeded(LeadLimits::ipKey((string) request()->ip()), LeadForm::RATE_LIMIT, LeadForm::RATE_LIMIT_SECONDS)
            || $this->limitExceeded(LeadLimits::phoneKey($phone), LeadForm::PHONE_LIMIT, LeadForm::PHONE_LIMIT_SECONDS);
    }

    /**
     * TG-43, run LAST (after validation, the IP / phone limiters and the
     * other cheap checks): bots are skipped entirely, otherwise a foreign
     * number counts against the global hourly cap; true (with a form error) when over it.
     */
    protected function foreignPhoneCapExceeded(string $phone): bool
    {
        if ($this->looksLikeBot() || ! ForeignPhoneLimit::exceeded($phone)) {
            return false;
        }

        $this->addError('form', __('validation.foreign_phone_limit'));

        return true;
    }

    /**
     * Register a hit and report (as a form error) whether the limit is now exceeded.
     */
    protected function limitExceeded(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if (! LeadLimits::exceeded($key, $maxAttempts, $decaySeconds)) {
            return false;
        }

        $this->addError('form', __('site.lead_form.error_rate_limited', [
            'seconds' => LeadLimits::availableIn($key),
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
     * Without click ids: they never become Livewire state (plan 40, YD-6 / YD-7).
     *
     * @return array<string, string>
     */
    protected function captureAttribution(): array
    {
        try {
            return Attribution::withoutClickIds(app(Attribution::class)->current(request()));
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * Plan 40: the session's Yandex click ids for the lead / order being
     * created now (nulls while the Admin switch is off). Read at submit
     * time, never kept in a property.
     *
     * @return array{yclid: string|null, ym_client_id: string|null}
     */
    protected function captureAdClickIds(): array
    {
        return AdClickIds::capture(request());
    }
}
