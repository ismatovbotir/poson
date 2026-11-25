<?php

namespace App\Actions\Office;

use App\Actions\Leads\CreateLead;
use App\Data\Leads\LeadData;
use App\Enums\AgentRole;
use App\Enums\AnalysisStatus;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\Lead;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The "Create lead" form of a business chat (plan 41 §6.9): staff confirm
 * or type the client's phone (a regex suggestion is only pre-filled, VO-7)
 * and pick the type. The phone becomes the chat's phone (a staff entry);
 * the lead is a TelegramBusiness lead, customer matched by phone, manager
 * = the chat's assignee when they can work with the type, else none; the
 * text is the latest topic and the chat reference only (VO-14).
 */
class CreateLeadFromBusinessChat
{
    public function __construct(protected CreateLead $createLead) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, BusinessChat $chat, string $phone, LeadType $type): Lead
    {
        if (! Gate::forUser($actor)->allows('createLead', $chat) || ! in_array($type, [LeadType::Quote, LeadType::ServiceRequest], true)) {
            throw new AuthorizationException;
        }

        $normalized = Phone::normalizeAny($phone);

        if ($normalized === null) {
            throw ValidationException::withMessages(['leadPhone' => __('validation.phone_any', ['attribute' => __('crm.leads.fields.phone')])]);
        }

        return DB::transaction(function () use ($actor, $chat, $normalized, $type): Lead {
            $locked = BusinessChat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isClient()) {
                throw new AuthorizationException;
            }

            if ($locked->lead_id !== null && Lead::query()->whereKey($locked->lead_id)->first()?->isOpen()) {
                throw ValidationException::withMessages(['leadPhone' => __('office.errors.lead_open')]);
            }

            $latest = $locked->analyses()->where('status', AnalysisStatus::Done->value)->latest('id')->first();
            $manager = $locked->assignee_id !== null ? User::query()->whereKey($locked->assignee_id)->where('is_active', true)->first() : null;

            $lead = $this->createLead->handle(new LeadData(
                source: LeadSource::TelegramBusiness,
                type: $type,
                name: filled($locked->first_name) ? (string) $locked->first_name : 'Telegram',
                phone: $normalized,
                locale: $latest?->language ?? (string) config('app.locale'),
                message: SyncLeadFromBusinessChat::message($locked, $latest?->topic),
                items: $latest !== null && $latest->role === ($type === LeadType::Quote ? AgentRole::Sales : AgentRole::Service)
                    ? SyncLeadFromBusinessChat::items(((array) $latest->lead_fields)['products'] ?? []) : [],
                managerId: $manager?->can($type->workPermission()) ? $manager->id : null,
                createdById: $actor->id,
            ));

            $locked->phone = $normalized;
            $locked->lead_id = $lead->id;
            $locked->customer_id ??= $lead->customer_id;
            $locked->save();

            $latest?->forceFill(['lead_action' => BusinessChatAnalysis::LEAD_CREATED, 'lead_id' => $lead->id])->save();

            return $lead;
        });
    }
}
