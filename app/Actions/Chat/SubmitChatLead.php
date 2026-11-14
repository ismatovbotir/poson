<?php

namespace App\Actions\Chat;

use App\Actions\Leads\CreateLead;
use App\Actions\Telegram\ConvertTelegramToLead;
use App\Actions\Telegram\RegisterTelegramUser;
use App\Data\Leads\LeadData;
use App\Data\Leads\LeadItemData;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\LeadFlow;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Support\Chat\LeadDialog;
use App\Support\Phone;
use App\Support\Telegram\InboxMessage;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One lead from a finished Telegram lead dialog (plan 28 C4, CB-8, CB-17).
 * Runs inside HandleTelegramUpdate's transaction with the telegram_users row
 * locked (the update_id is deduplicated there), so a double tap or a
 * Telegram retry finds the dialog already cleared and creates nothing.
 *
 *  - Source LeadSource::Telegram, type from the flow (LeadFlow::leadType),
 *    both from the server.
 *  - Phone: the registered contact-share phone only; the anonymous +888
 *    number or a missing phone is refused (the bot offers a manager).
 *  - No phone match to a customer (matchCustomer false): the customer only
 *    through the conversation's verified link, as ConvertTelegramToLead.
 *  - A product chosen from a search result is checked again (published)
 *    and dropped when it is not.
 *  - Limits (CB-8, not GuardsLeadSubmissions, whose IP would be Telegram's):
 *    per customer chatbot.leads.per_day_user (3), over all chats
 *    per_hour_global (30), hit last, after every refusal.
 *  - The dialog is cleared in the same transaction; LeadCreated is raised
 *    once by CreateLead after commit.
 */
class SubmitChatLead
{
    public const REFUSED_PHONE = 'phone';

    public const REFUSED_LIMIT = 'limit';

    public const REFUSED_DETAILS = 'details';

    public function __construct(
        private readonly CreateLead $createLead,
        private readonly RecordChatStat $stats,
    ) {}

    /**
     * @param  array{flow: LeadFlow, step: mixed, started_at: int, answers: array{details: string, product_id: int|null}}  $state
     * @return Lead|self::REFUSED_* the lead, or why none was created
     */
    public function handle(TelegramUser $user, array $state): Lead|string
    {
        $phone = self::usablePhone($user);

        if ($phone === null || ! $user->isRegistered() || $user->isBanned()) {
            return self::REFUSED_PHONE;
        }

        $flow = $state['flow'];
        $productId = $flow === LeadFlow::Quote ? $state['answers']['product_id'] : null;
        $product = $productId !== null ? Product::query()->visible()->whereKey($productId)->first(['products.id']) : null;

        // The product became hidden and nothing else was written: no empty lead, ask for the details.
        if ($productId !== null && ! $product instanceof Product && LeadDialog::answer($state['answers']['details']) === '' && ! $flow->detailsOptional()) {
            return self::REFUSED_DETAILS;
        }

        [$attachCustomer] = ConvertTelegramToLead::customerDecision($user);

        if (RateLimiter::hit('chat-lead:user:'.$user->id, 86400) > (int) config('chatbot.leads.per_day_user', 3)
            || RateLimiter::hit('chat-lead:global', 3600) > (int) config('chatbot.leads.per_hour_global', 30)) {
            return self::REFUSED_LIMIT;
        }

        $name = InboxMessage::displayName($user);
        $details = LeadDialog::answer($state['answers']['details']);

        $lead = $this->createLead->handle(new LeadData(
            source: LeadSource::Telegram,
            type: $flow->leadType(),
            name: $name !== '' ? $name : __('telegram_inbox.unnamed', [], 'uz'),
            phone: $phone,
            locale: $user->locale ?? (string) config('app.locale'),
            company: $user->declared_company,
            message: $details !== '' ? $details : null,
            items: $product instanceof Product ? [new LeadItemData($product->id)] : [],
            matchCustomer: false,
            customerId: $attachCustomer ? $user->customer_id : null,
        ));

        LeadDialog::clear($user);
        $this->stats->handle(ChatChannel::Telegram, ChatMetric::Lead);

        return $lead;
    }

    /**
     * The registered phone a lead may carry, or null (+888 never reaches the CRM, TG-29).
     */
    public static function usablePhone(TelegramUser $user): ?string
    {
        return RegisterTelegramUser::isAnonymousNumber($user->phone) ? null : Phone::normalizeAny($user->phone);
    }
}
