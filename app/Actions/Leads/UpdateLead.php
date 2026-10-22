<?php

namespace App\Actions\Leads;

use App\Data\Leads\LeadData;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit a lead's contact data, type, estimate and items (admin form).
 *
 * - The source only changes between the manual channels (phone, manual,
 *   telegram); a site lead stays a site lead.
 * - A new phone re-checks the duplicate flag (earlier leads within the
 *   window before this lead) and, when no customer is linked yet, the
 *   customer match. Later leads flagged as duplicates of this one that
 *   don't share the new phone lose the flag.
 * - A new type the current manager can't work with unassigns the lead.
 * - The manager and the status are not changed here (AssignLead,
 *   ChangeLeadStatus); attribution is never edited.
 */
class UpdateLead
{
    /**
     * Channels a lead entered in the admin can have.
     *
     * @var list<LeadSource>
     */
    public const MANUAL_SOURCES = [LeadSource::Phone, LeadSource::Manual, LeadSource::Telegram];

    public function __construct(protected SyncLeadItems $syncItems) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, LeadData $data): Lead
    {
        $phone = Phone::normalize($data->phone);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('validation.uz_phone', ['attribute' => __('crm.leads.fields.phone')])]);
        }

        return DB::transaction(function () use ($lead, $data, $phone): Lead {
            $phoneChanged = $phone !== $lead->phone;

            $lead->fill([
                'type' => $data->type,
                'name' => mb_substr(trim($data->name), 0, 120),
                'phone' => $phone,
                'email' => CreateLead::clean($data->email, 255, lower: true),
                'company' => CreateLead::clean($data->company, 160),
                'inn' => CreateLead::clean($data->inn, 9),
                'message' => CreateLead::clean($data->message, 5000),
                'estimated_amount' => $data->estimatedAmount,
            ]);

            if (in_array($lead->getOriginal('source'), self::MANUAL_SOURCES, true) && in_array($data->source, self::MANUAL_SOURCES, true)) {
                $lead->source = $data->source;
            }

            if ($phoneChanged) {
                $previous = Lead::query()
                    ->matchingPhone($phone)
                    ->where('id', '<', $lead->id)
                    ->where('created_at', '>=', $lead->created_at->copy()->subDays(Lead::DUPLICATE_WINDOW_DAYS))
                    ->latest('id')
                    ->first(['id', 'duplicate_of_id']);

                $lead->duplicate_of_id = $previous?->duplicate_of_id ?? $previous?->id;

                Lead::query()->where('duplicate_of_id', $lead->id)->where('phone', '!=', $phone)->update(['duplicate_of_id' => null]);

                if ($lead->customer_id === null) {
                    $lead->customer_id = CreateLead::matchCustomer($phone)?->id;
                }
            }

            if ($lead->manager_id !== null && $lead->isDirty('type')) {
                $manager = User::query()->find($lead->manager_id);

                if (! $manager instanceof User || ! $manager->can($data->type->workPermission())) {
                    $lead->manager_id = null;
                }
            }

            $lead->save();

            $this->syncItems->handle($lead, $data->items);

            return $lead;
        });
    }
}
