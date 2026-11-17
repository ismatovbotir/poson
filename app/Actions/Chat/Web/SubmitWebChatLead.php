<?php

namespace App\Actions\Chat\Web;

use App\Actions\Chat\RecordChatStat;
use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\LeadFlow;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\Product;
use App\Models\WebChat;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatVersion;
use Illuminate\Support\Facades\DB;

/**
 * The website chat's inline lead form (plan 28 C5 §19.1, §19.3, CB-8, CB-17,
 * WC-8, WC-9), through the site lead path (SubmitSiteLead::lead()).
 *
 * Reachable only from the widget's submitLead(), which BEFORE calling runs
 * GuardsLeadSubmissions in Site\LeadForm's order (C5b): validate
 * (PhoneNumber(public: true), name ≤ 120, details ≤ CreateLead::MESSAGE_LIMIT)
 * → Phone::normalizeAny → leadLimitsExceeded (the IP / phone budget shared
 * with Site\LeadForm) → foreignPhoneCapExceeded → looksLikeBot (honeypot
 * fax_ext; renderedAt = dialog.form.at, the server time the card opened).
 * This action re-checks nothing of that and trusts only the normalized
 * phone it is given.
 *
 * The server decides everything else, under the web_chats row lock: the
 * flow, type and product come from dialog.form (never from the client); a
 * quote's product is re-checked with Product::visible() and dropped when
 * hidden; the service flow never carries a product (SubmitSiteLead would
 * make it a quote and the service chat would never be alerted); a callback
 * never does. One lead per chat: created only while lead_id is null. The
 * visitor's phone never matches a CRM customer (matchCustomer false, WC-9).
 * LeadCreated fires once, after commit (CreateLead). The form closes, the
 * Lead stat is counted for the web, the version is bumped.
 */
class SubmitWebChatLead
{
    public const NAME_MAX = 120;

    public function __construct(
        private readonly SubmitSiteLead $site,
        private readonly RecordChatStat $stats,
    ) {}

    /**
     * @param  string  $phone  Already normalized by the caller (Phone::normalizeAny)
     * @return Lead|null null when refused: switched off, banned, closed, no open form or already a lead
     */
    public function handle(WebChat $chat, string $name, string $phone, ?string $details): ?Lead
    {
        if (! WebChatSwitch::isOpen()) {
            return null;
        }

        return DB::transaction(function () use ($chat, $name, $phone, $details): ?Lead {
            $locked = WebChat::query()->whereKey($chat->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isBanned() || $locked->isClosed() || $locked->isExpired() || $locked->lead_id !== null) {
                return null;
            }

            $form = WebChatDialog::form($locked->dialog);

            if ($form === null) {
                return null;
            }

            $product = $form['flow'] === LeadFlow::Quote && $form['product_id'] !== null
                ? Product::query()->visible()->whereKey($form['product_id'])->first(['products.id', 'products.sku'])
                : null;
            $name = mb_substr(trim($name), 0, self::NAME_MAX);

            $lead = $this->site->lead(new SiteLeadData(
                name: $name,
                phone: $phone,
                company: null,
                message: $details,
                locale: $locked->locale,
                source: LeadSource::Site,
                type: $form['flow']->leadType(),
                productId: $product?->id,
                productSku: $product?->sku,
                matchCustomer: false,
            ));

            $locked->forceFill([
                'lead_id' => $lead->id,
                'dialog' => null,
                'name' => $locked->name ?? (mb_substr($name, 0, 64) ?: null),
                'phone' => $locked->phone ?? $phone,
                'last_message_at' => now(),
            ])->save();

            DB::afterCommit(fn () => $this->stats->handle(ChatChannel::Web, ChatMetric::Lead));
            WebChatVersion::bump($locked->id);

            return $lead;
        });
    }
}
