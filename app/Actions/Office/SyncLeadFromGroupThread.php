<?php

namespace App\Actions\Office;

use App\Actions\Leads\CreateLead;
use App\Data\Leads\LeadData;
use App\Enums\ActivityType;
use App\Enums\AgentRole;
use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Enums\LeadSource;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupThread;
use App\Models\User;
use App\Support\Office\OfficeSettings;
use Illuminate\Validation\ValidationException;

/**
 * The CRM side of one analysed group thread (plan 44 §2, OG-6), sales and
 * service only, called inside the save transaction (group row locked):
 *
 *  - the thread's lead is still open → an AiSummary activity (analysis id
 *    and topic only), like SyncLeadFromBusinessChat;
 *  - otherwise a new lead ONLY when office.auto_lead is on, the group is
 *    tracked and assigned (manager = the assignee, active and allowed to
 *    work the lead type) and one of the thread's client members has a role
 *    set by staff (role_set_by_id) and either a linked customer or their
 *    OWN shared contact phone (the member phone column, stored only when
 *    contact.user_id is the sender). Never a phone from the text or the AI;
 *    an unknown member never gets a lead. A customer-linked group does NOT
 *    fall back to group.customer_id: OG-6 ties the lead to a member staff
 *    vouched for, never to whoever writes in a customer's group;
 *  - lead.message = topic + "Telegram group #id" (VO-14), never the summary.
 */
class SyncLeadFromGroupThread
{
    public function __construct(protected CreateLead $createLead) {}

    /**
     * @param  list<int>  $clientMemberIds  The validated client members of the thread (router labels)
     * @param  array<string, mixed>  $fields  Validated lead hints (AnalysisOutput)
     * @return array{action: string, lead_id: ?int}
     */
    public function handle(TelegramGroup $group, TelegramGroupThread $thread, TelegramGroupAnalysis $analysis, ?AgentRole $role, ?string $topic, ?string $language, array $clientMemberIds, array $fields): array
    {
        $type = $role?->leadType();

        if ($type === null) {
            return ['action' => TelegramGroupAnalysis::LEAD_NONE, 'lead_id' => null];
        }

        $lead = $thread->lead_id !== null ? Lead::query()->find($thread->lead_id) : null;

        if ($lead instanceof Lead && $lead->isOpen()) {
            $lead->activities()->create([
                'type' => ActivityType::AiSummary,
                'meta' => array_filter(['group_analysis_id' => $analysis->id, 'topic' => $topic], fn (mixed $value): bool => $value !== null),
                'created_at' => now(),
            ]);

            return ['action' => TelegramGroupAnalysis::LEAD_UPDATED, 'lead_id' => $lead->id];
        }

        if (! OfficeSettings::autoLead() || $group->status !== GroupStatus::Tracked || $group->assignee_id === null) {
            return ['action' => TelegramGroupAnalysis::LEAD_SUGGESTED, 'lead_id' => null];
        }

        $manager = User::query()->whereKey($group->assignee_id)->where('is_active', true)->first();

        if (! $manager instanceof User || ! $manager->can($type->workPermission())) {
            return ['action' => TelegramGroupAnalysis::LEAD_SUGGESTED, 'lead_id' => null];
        }

        $member = $this->eligibleMember($group, $clientMemberIds);

        if ($member === null) {
            return ['action' => TelegramGroupAnalysis::LEAD_NEEDS_PHONE, 'lead_id' => null];
        }

        $customer = $member->customer_id !== null ? Customer::query()->find($member->customer_id) : null;
        $phone = $member->phone ?? $customer?->phone;

        if ($phone === null || $phone === '') {
            return ['action' => TelegramGroupAnalysis::LEAD_NEEDS_PHONE, 'lead_id' => null];
        }

        try {
            $lead = $this->createLead->handle(new LeadData(
                source: LeadSource::TelegramBusiness,
                type: $type,
                name: filled($member->first_name) ? (string) $member->first_name : 'Telegram',
                phone: $phone,
                locale: $language ?? (string) config('app.locale'),
                message: self::message($group, $topic),
                items: SyncLeadFromBusinessChat::items($fields['products'] ?? []),
                managerId: $manager->id,
                matchCustomer: $member->phone !== null && $customer === null,
                customerId: $customer?->id,
                deviceBrand: $type->isService() ? ($fields['device_brand'] ?? null) : null,
                deviceModel: $type->isService() ? ($fields['device_model'] ?? null) : null,
            ));
        } catch (ValidationException) {
            return ['action' => TelegramGroupAnalysis::LEAD_NEEDS_PHONE, 'lead_id' => null];
        }

        $thread->forceFill(['lead_id' => $lead->id, 'customer_id' => $thread->customer_id ?? $lead->customer_id])->save();

        return ['action' => TelegramGroupAnalysis::LEAD_CREATED, 'lead_id' => $lead->id];
    }

    /**
     * The lead text: the validated topic and the group reference only (VO-14).
     */
    public static function message(TelegramGroup $group, ?string $topic): string
    {
        return trim(($topic ?? '')."\nTelegram group #".$group->id);
    }

    /**
     * The first client member of the thread (re-read under the lock) whose role staff set and who has a
     * linked customer or their own shared phone (OG-6).
     *
     * @param  list<int>  $memberIds
     */
    protected function eligibleMember(TelegramGroup $group, array $memberIds): ?TelegramGroupMember
    {
        if ($memberIds === []) {
            return null;
        }

        return TelegramGroupMember::query()
            ->where('telegram_group_id', $group->id)
            ->whereKey($memberIds)
            ->where('role', GroupMemberRole::Client->value)
            ->where('is_bot', false)
            ->whereNotNull('role_set_by_id')
            ->where(fn ($query) => $query->whereNotNull('customer_id')->orWhereNotNull('phone'))
            ->orderBy('id')
            ->first();
    }
}
