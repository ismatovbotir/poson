<?php

namespace App\Actions\Repairs\Concerns;

use App\Data\Repairs\RepairData;
use App\Enums\RepairCoverage;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Validation\ValidationException;

/**
 * Checks and cleans the staff input of a repair (create and update share it):
 * lengths on every text field (P24-21), a normalized phone, the contact
 * belonging to the customer, a warranty reference, and amounts only when the
 * caller may handle costs, as integer tiyin under a cap (P24-20).
 */
trait PreparesRepairInput
{
    /**
     * Limits of the short text columns.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        'contact_name' => 120,
        'company' => 160,
        'device_brand' => 80,
        'device_model' => 120,
        'serial_number' => 64,
        'accessories' => 500,
        'warranty_reference' => 120,
    ];

    /**
     * @return array<string, mixed> columns for the repairs table (amounts only when allowed)
     *
     * @throws ValidationException
     */
    protected function attributesFrom(RepairData $data, User $actor): array
    {
        $phone = Phone::normalizeAny($data->contactPhone);

        if ($phone === null) {
            throw ValidationException::withMessages(['contact_phone' => __('validation.phone_any', ['attribute' => __('repairs.fields.contact_phone')])]);
        }

        $attributes = [
            'contact_name' => $this->required($data->contactName, 'contact_name', self::LIMITS['contact_name']),
            'contact_phone' => $phone,
            'company' => $this->optional($data->company, 'company', self::LIMITS['company']),
            'device_type' => $data->deviceType,
            'device_brand' => $this->optional($data->deviceBrand, 'device_brand', self::LIMITS['device_brand']),
            'device_model' => $this->optional($data->deviceModel, 'device_model', self::LIMITS['device_model']),
            'serial_number' => $this->optional($data->serialNumber, 'serial_number', self::LIMITS['serial_number']),
            'accessories' => $this->optional($data->accessories, 'accessories', self::LIMITS['accessories']),
            'intake_condition' => $this->required($data->intakeCondition, 'intake_condition', Repair::TEXT_MAX),
            'problem' => $this->required($data->problem, 'problem', Repair::TEXT_MAX),
            'diagnosis' => $this->optional($data->diagnosis, 'diagnosis', Repair::TEXT_MAX),
            'work_done' => $this->optional($data->workDone, 'work_done', Repair::TEXT_MAX),
            'parts_used' => $this->optional($data->partsUsed, 'parts_used', Repair::TEXT_MAX),
            'coverage' => $data->coverage,
            'warranty_reference' => $this->optional($data->warrantyReference, 'warranty_reference', self::LIMITS['warranty_reference']),
            'locale' => in_array($data->locale, config('app.supported_locales'), true) ? $data->locale : config('app.locale'),
        ];

        if ($data->coverage === RepairCoverage::Warranty && $attributes['warranty_reference'] === null) {
            throw ValidationException::withMessages(['warranty_reference' => __('repairs.errors.warranty_reference_required')]);
        }

        if ($data->coverage === RepairCoverage::Paid) {
            $attributes['warranty_reference'] = null;
        }

        [$attributes['customer_id'], $attributes['contact_id']] = $this->resolveCustomer($data, $actor);

        if ($data->costsIncluded && $actor->can('repairs.costs')) {
            $attributes['estimate_amount'] = $this->amount($data->estimateAmount, 'estimate_amount');
            $attributes['final_amount'] = $this->amount($data->finalAmount, 'final_amount');
        }

        return $attributes;
    }

    /**
     * The lead a new repair comes from (create only): a service request the
     * actor may view, or null.
     *
     * @throws ValidationException
     */
    protected function resolveLead(RepairData $data, User $actor): ?int
    {
        if ($data->leadId === null) {
            return null;
        }

        $lead = Lead::query()->find($data->leadId);

        if (! $lead instanceof Lead || ! $lead->type->isService() || ! $actor->can('view', $lead)) {
            throw ValidationException::withMessages(['lead_id' => __('repairs.errors.lead_invalid')]);
        }

        return $lead->id;
    }

    /**
     * @return array{0: int|null, 1: int|null} customer id, contact id
     *
     * @throws ValidationException
     */
    private function resolveCustomer(RepairData $data, User $actor): array
    {
        if ($data->customerId === null) {
            if ($data->contactId !== null) {
                throw ValidationException::withMessages(['contact_id' => __('repairs.errors.contact_customer')]);
            }

            return [null, null];
        }

        $customer = Customer::query()->find($data->customerId);

        if (! $customer instanceof Customer || ! $actor->can('view', $customer)) {
            throw ValidationException::withMessages(['customer_id' => __('repairs.errors.customer_invalid')]);
        }

        if ($data->contactId !== null && ! Contact::query()->whereKey($data->contactId)->where('customer_id', $customer->id)->exists()) {
            throw ValidationException::withMessages(['contact_id' => __('repairs.errors.contact_customer')]);
        }

        return [$customer->id, $data->contactId];
    }

    /**
     * @throws ValidationException
     */
    private function amount(?int $tiyin, string $key): ?int
    {
        if ($tiyin === null) {
            return null;
        }

        if ($tiyin < 0 || $tiyin > Repair::AMOUNT_MAX_SUMS * 100) {
            throw ValidationException::withMessages([$key => __('repairs.errors.amount_invalid')]);
        }

        return $tiyin;
    }

    /**
     * @throws ValidationException
     */
    private function required(string $value, string $key, int $max): string
    {
        $value = $this->optional($value, $key, $max);

        if ($value === null) {
            throw ValidationException::withMessages([$key => __('validation.required', ['attribute' => __('repairs.fields.'.$key)])]);
        }

        return $value;
    }

    /**
     * @throws ValidationException
     */
    private function optional(?string $value, string $key, int $max): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$key => __('validation.max.string', ['attribute' => __('repairs.fields.'.$key), 'max' => $max])]);
        }

        return $value;
    }
}
