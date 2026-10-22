<?php

namespace App\Actions\Leads;

use App\Data\Leads\LeadData;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Events\LeadCreated;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a lead from any channel (plan 05 C2): site form (SubmitSiteLead),
 * admin form, later Telegram. In one transaction:
 *
 *  1. normalize the phone (+998XXXXXXXXX);
 *  2. flag a duplicate: an earlier lead with the same phone within
 *     Lead::DUPLICATE_WINDOW_DAYS → duplicate_of_id (its original, if it is
 *     a duplicate itself). Never merged;
 *  3. match a customer by phone (the customer's own, then a contact's);
 *  4. manager: the given one (must be able to work with the type); a lead
 *     without a staff creator (site, later the bot) that is a sales lead
 *     goes to the matched customer's manager when that user can still work
 *     with sales leads (decision 2026-10-22). A lead entered by staff keeps
 *     their choice, unassigned included;
 *  5. items with SKU / name snapshots (SyncLeadItems);
 *  6. a "created" activity;
 *  7. LeadCreated, dispatched after commit (Telegram in 5c).
 *
 * No personal data is logged here.
 */
class CreateLead
{
    public function __construct(protected SyncLeadItems $syncItems) {}

    /**
     * @throws ValidationException
     */
    public function handle(LeadData $data): Lead
    {
        $phone = Phone::normalize($data->phone);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('validation.uz_phone', ['attribute' => __('crm.leads.fields.phone')])]);
        }

        $manager = $data->managerId !== null ? static::eligibleManager($data->managerId, $data) : null;

        return DB::transaction(function () use ($data, $phone, $manager): Lead {
            $previous = Lead::query()
                ->matchingPhone($phone)
                ->where('created_at', '>=', now()->subDays(Lead::DUPLICATE_WINDOW_DAYS))
                ->latest('id')
                ->first(['id', 'duplicate_of_id']);

            $customer = static::matchCustomer($phone);
            $autoAssigned = false;

            if ($data->createdById === null && $manager === null && $data->type->isSales() && $customer?->manager_id !== null) {
                $manager = User::query()->whereKey($customer->manager_id)->where('is_active', true)->first();
                $manager = $manager?->can('leads.sales') ? $manager : null;
                $autoAssigned = $manager !== null;
            }

            $lead = Lead::query()->create([
                'source' => $data->source,
                'type' => $data->type,
                'status' => LeadStatus::New,
                'name' => mb_substr(trim($data->name), 0, 120),
                'phone' => $phone,
                'email' => static::clean($data->email, 255, lower: true),
                'company' => static::clean($data->company, 160),
                'inn' => static::clean($data->inn, 9),
                'message' => static::clean($data->message, 5000),
                'customer_id' => $customer?->id,
                'manager_id' => $manager?->id,
                'created_by_id' => $data->createdById,
                'duplicate_of_id' => $previous?->duplicate_of_id ?? $previous?->id,
                'estimated_amount' => $data->estimatedAmount,
                'locale' => in_array($data->locale, config('app.supported_locales'), true) ? $data->locale : config('app.locale'),
                'page_url' => static::clean($data->pageUrl, 2048),
                'traffic_channel' => $data->trafficChannel,
                'traffic_source' => static::clean($data->trafficSource, 255),
                'landing_path' => static::clean($data->landingPath, 2048),
                'utm' => $data->utm !== [] ? $data->utm : null,
                'status_changed_at' => now(),
            ]);

            $this->syncItems->handle($lead, $data->items);

            $lead->activities()->create([
                'user_id' => $data->createdById,
                'type' => ActivityType::Created,
                'meta' => array_filter([
                    'source' => $data->source->value,
                    'type' => $data->type->value,
                    'duplicate_of_id' => $lead->duplicate_of_id,
                    'customer_id' => $lead->customer_id,
                    'manager_id' => $lead->manager_id,
                    'auto_assigned' => $autoAssigned ?: null,
                ], fn (mixed $value): bool => $value !== null),
                'created_at' => now(),
            ]);

            LeadCreated::dispatch($lead);

            return $lead;
        });
    }

    /**
     * The customer whose phone (or a contact's phone) is this number; the oldest wins.
     */
    public static function matchCustomer(string $phone): ?Customer
    {
        $customer = Customer::query()->matchingPhone($phone)->orderBy('id')->first();

        if ($customer instanceof Customer) {
            return $customer;
        }

        $contact = Contact::query()->matchingPhone($phone)->with('customer')->orderBy('id')->first();

        return $contact?->customer;
    }

    /**
     * Trimmed, length-capped text; empty → null.
     */
    public static function clean(?string $value, int $max, bool $lower = false): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = mb_substr($value, 0, $max);

        return $lower ? mb_strtolower($value) : $value;
    }

    /**
     * An active user who can work with the lead's type.
     *
     * @throws ValidationException
     */
    protected static function eligibleManager(int $managerId, LeadData $data): User
    {
        $manager = User::query()->whereKey($managerId)->where('is_active', true)->first();

        if (! $manager instanceof User || ! $manager->can($data->type->workPermission())) {
            throw ValidationException::withMessages(['manager_id' => __('crm.leads.errors.manager_invalid')]);
        }

        return $manager;
    }
}
