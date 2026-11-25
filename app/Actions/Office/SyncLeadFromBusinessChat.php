<?php

namespace App\Actions\Office;

use App\Actions\Leads\CreateLead;
use App\Data\Leads\LeadData;
use App\Data\Leads\LeadItemData;
use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use App\Support\Office\OfficeSettings;
use Illuminate\Validation\ValidationException;

/**
 * The CRM side of a done analysis (plan 41 §6.8–6.9, VO-7, VO-14), sales
 * and service roles only:
 *
 *  - the chat's lead is still open → an AiSummary activity (analysis id and
 *    topic only) and, for a service request, empty device fields filled;
 *  - otherwise a new lead ONLY when office.auto_lead is on, the chat has an
 *    assignee who can work with the lead type (manager = assignee) and a
 *    trusted phone is known: the client's own shared contact or a staff
 *    entry on the chat (customer matched by phone), else the linked
 *    customer's phone (that customer, no match). Never a phone the regex
 *    found in the text, never one the AI wrote;
 *  - an unassigned chat or auto-lead off → "suggested" (the Admin may use
 *    the "Create lead" form); no phone → "needs_phone".
 * lead.message = topic + "Telegram Business chat #id", never the summary.
 * Items: product matching is EXACT-SKU only — a name or model the AI wrote
 * becomes an item only when it equals an active product's SKU; nothing is
 * fuzzy-matched, priced or invented.
 */
class SyncLeadFromBusinessChat
{
    public function __construct(protected CreateLead $createLead) {}

    /**
     * @return array{action: string, lead_id: ?int}
     */
    public function handle(BusinessChat $chat, BusinessChatAnalysis $analysis): array
    {
        $type = $analysis->role?->leadType();

        if ($type === null) {
            return ['action' => BusinessChatAnalysis::LEAD_NONE, 'lead_id' => null];
        }

        // Re-read under the chat row lock (the caller's transaction): a lead created meanwhile by the
        // form or another analysis is seen here, so a chat never gets two leads.
        $chat = BusinessChat::query()->whereKey($chat->id)->lockForUpdate()->first() ?? $chat;

        $lead = $chat->lead_id !== null ? Lead::query()->find($chat->lead_id) : null;

        if ($lead instanceof Lead && $lead->isOpen()) {
            $this->update($lead, $analysis);

            return ['action' => BusinessChatAnalysis::LEAD_UPDATED, 'lead_id' => $lead->id];
        }

        if (! OfficeSettings::autoLead() || $chat->assignee_id === null) {
            return ['action' => BusinessChatAnalysis::LEAD_SUGGESTED, 'lead_id' => null];
        }

        $manager = User::query()->whereKey($chat->assignee_id)->where('is_active', true)->first();

        if (! $manager instanceof User || ! $manager->can($type->workPermission())) {
            return ['action' => BusinessChatAnalysis::LEAD_SUGGESTED, 'lead_id' => null];
        }

        $customer = $chat->customer_id !== null ? Customer::query()->find($chat->customer_id) : null;
        $phone = $chat->phone ?? $customer?->phone;

        if ($phone === null) {
            return ['action' => BusinessChatAnalysis::LEAD_NEEDS_PHONE, 'lead_id' => null];
        }

        $fields = (array) $analysis->lead_fields;

        try {
            $lead = $this->createLead->handle(new LeadData(
                source: LeadSource::TelegramBusiness,
                type: $type,
                name: filled($chat->first_name) ? (string) $chat->first_name : 'Telegram',
                phone: $phone,
                locale: $analysis->language ?? (string) config('app.locale'),
                message: self::message($chat, $analysis->topic),
                items: self::items($fields['products'] ?? []),
                managerId: $manager->id,
                matchCustomer: $chat->phone !== null,
                customerId: $chat->phone !== null ? null : $customer?->id,
                deviceBrand: $type->isService() ? ($fields['device_brand'] ?? null) : null,
                deviceModel: $type->isService() ? ($fields['device_model'] ?? null) : null,
            ));
        } catch (ValidationException) {
            return ['action' => BusinessChatAnalysis::LEAD_NEEDS_PHONE, 'lead_id' => null];
        }

        $chat->lead_id = $lead->id;
        $chat->customer_id ??= $lead->customer_id;
        $chat->save();

        return ['action' => BusinessChatAnalysis::LEAD_CREATED, 'lead_id' => $lead->id];
    }

    /**
     * The lead text: the validated topic and the chat reference only (VO-14).
     */
    public static function message(BusinessChat $chat, ?string $topic): string
    {
        return trim(($topic ?? '')."\nTelegram Business chat #".$chat->id);
    }

    /**
     * @param  mixed  $products  Plain product names / models from the analysis
     * @return list<LeadItemData>
     */
    public static function items(mixed $products): array
    {
        $skus = array_values(array_filter(is_array($products) ? $products : [], fn (mixed $value): bool => is_string($value) && $value !== ''));

        if ($skus === []) {
            return [];
        }

        return Product::query()->active()->whereIn('sku', $skus)->limit(5)->pluck('id')
            ->map(fn (int $id): LeadItemData => new LeadItemData($id))->values()->all();
    }

    protected function update(Lead $lead, BusinessChatAnalysis $analysis): void
    {
        $lead->activities()->create([
            'type' => ActivityType::AiSummary,
            'meta' => array_filter(['analysis_id' => $analysis->id, 'topic' => $analysis->topic], fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ]);

        if (! $lead->type->isService()) {
            return;
        }

        $fields = (array) $analysis->lead_fields;

        foreach (['device_brand' => 80, 'device_model' => 120] as $column => $max) {
            if (blank($lead->{$column}) && filled($fields[$column] ?? null)) {
                $lead->{$column} = CreateLead::cleanLine((string) $fields[$column], $max);
            }
        }

        if ($lead->isDirty()) {
            $lead->save();
        }
    }
}
