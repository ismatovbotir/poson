<?php

namespace App\Enums;

/**
 * Yandex Metrika / GA4 conversion goals (plan 40 §1). The value is the goal
 * ID the owner creates in Metrika ("JavaScript event"). Online goals are sent
 * by resources/js/analytics.js without any parameters (YD-3); the offline
 * ones (order_paid, quote_accepted) only go out in the offline-conversion CSV.
 *
 * Every ad lead goal starts with "lead_" (one optional "starts with lead_"
 * goal in Metrika). careers_application must never be a Direct goal.
 */
enum AnalyticsGoal: string
{
    case LeadCallback = 'lead_callback';
    case LeadProductQuote = 'lead_product_quote';
    case LeadProductService = 'lead_product_service';
    case LeadCartQuote = 'lead_cart_quote';
    case LeadServiceRequest = 'lead_service_request';
    case LeadChat = 'lead_chat';
    case OrderPlaced = 'order_placed';
    case ClickPhone = 'click_phone';
    case ClickTelegram = 'click_telegram';
    case ChatStarted = 'chat_started';
    case ChatHandoff = 'chat_handoff';
    case CareersApplication = 'careers_application';
    case AddToCart = 'add_to_cart';
    case OrderPaid = 'order_paid';
    case QuoteAccepted = 'quote_accepted';

    public function label(): string
    {
        return __("enums.analytics_goal.{$this->value}");
    }

    /**
     * What makes the goal fire (Settings → Tracking help).
     */
    public function trigger(): string
    {
        return __("analytics.goals.trigger.{$this->value}");
    }

    /**
     * A key goal for Direct (leads, orders, offline payments and accepted quotes).
     */
    public function isAdGoal(): bool
    {
        return str_starts_with($this->value, 'lead_')
            || in_array($this, [self::OrderPlaced, self::OrderPaid, self::QuoteAccepted], true);
    }

    /**
     * Must never be chosen as a Direct goal (not a customer action).
     */
    public function isNeverInDirect(): bool
    {
        return $this === self::CareersApplication;
    }

    /**
     * Sent only in the offline-conversion CSV, never from the browser.
     */
    public function isOffline(): bool
    {
        return in_array($this, [self::OrderPaid, self::QuoteAccepted], true);
    }

    /**
     * key | micro | never: the role of the goal in Direct (help table).
     */
    public function directRole(): string
    {
        return match (true) {
            $this->isNeverInDirect() => 'never',
            $this->isAdGoal() => 'key',
            default => 'micro',
        };
    }

    /**
     * Goals of the offline-conversion CSV.
     *
     * @return list<self>
     */
    public static function offline(): array
    {
        return array_values(array_filter(self::cases(), fn (self $goal): bool => $goal->isOffline()));
    }
}
