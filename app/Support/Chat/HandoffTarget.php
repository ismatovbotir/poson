<?php

namespace App\Support\Chat;

use App\Enums\ChatAction;

/**
 * Where a matched hand-off rule sends the customer (plan 36 §4 item 7).
 *
 *  - "sales": the live hand-off to the sales inbox, as always;
 *  - "service": no live hand-off (engineers have no inbox): the reply
 *    becomes the service-request lead dialog (LeadFlow::Service).
 *
 * The rule's own param wins; a rule without a valid one follows the global
 * chat bot setting (handoff_target, "any" and anything unknown = sales). The
 * "manager" rule, the menu button, the AI fallback and the hand-off button
 * are always sales, whatever the global says (security MED-5).
 *
 * Every channel must call opensServiceDialog() on an engine reply BEFORE its
 * first isHandoff() check (and act on it once), so a new channel cannot miss it.
 */
final class HandoffTarget
{
    public const SALES = 'sales';

    public const SERVICE = 'service';

    /**
     * The values a hand-off rule may store in params.handoff_target.
     */
    public const RULE_TARGETS = [self::SALES, self::SERVICE];

    /**
     * System rule whose hand-off is always sales.
     */
    public const MANAGER_KEY = 'manager';

    /**
     * @param  array<string, mixed>|null  $ruleParams
     * @return 'sales'|'service'
     */
    public static function for(?array $ruleParams): string
    {
        $target = $ruleParams['handoff_target'] ?? null;

        if (is_string($target) && in_array($target, self::RULE_TARGETS, true)) {
            return $target;
        }

        return ChatSettings::all()['handoff_target'] === self::SERVICE ? self::SERVICE : self::SALES;
    }

    /**
     * Whether this engine reply is a hand-off that must open the service dialog instead.
     *
     * @param  array{rules: array<int, array<string, mixed>>}|null  $compiled  The compiled rules (RuleIndex::load())
     */
    public static function opensServiceDialog(ChatReply $reply, ?array $compiled): bool
    {
        if (! $reply->isHandoff() || $reply->ruleId === null) {
            return false;
        }

        $rule = $compiled['rules'][$reply->ruleId] ?? null;

        if (! is_array($rule) || ($rule['action'] ?? null) !== ChatAction::Handoff->value || ($rule['key'] ?? null) === self::MANAGER_KEY) {
            return false;
        }

        return self::for(is_array($rule['params'] ?? null) ? $rule['params'] : null) === self::SERVICE;
    }
}
