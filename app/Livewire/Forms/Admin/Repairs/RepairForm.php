<?php

namespace App\Livewire\Forms\Admin\Repairs;

use App\Data\Repairs\RepairData;
use App\Enums\RepairCoverage;
use App\Enums\RepairDeviceType;
use App\Models\Repair;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\Money;
use App\Support\Phone;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State and validation of the admin repair form (create / edit). Saved
 * through CreateRepair / UpdateRepair. Amounts are typed in whole soʻm and
 * converted to tiyin here. Without repairs.costs the amount fields are never
 * filled (setRepair) and never accepted (rules): no amounts reach the
 * browser state of such a user (P24-20).
 */
class RepairForm extends Form
{
    public string $contact_name = '';

    /**
     * The customer's language (receipt and messages).
     */
    public string $locale = 'uz';

    public string $contact_phone = '';

    public string $company = '';

    public string $customer_id = '';

    public string $contact_id = '';

    public string $device_type = 'scanner';

    public string $device_brand = '';

    public string $device_model = '';

    public string $serial_number = '';

    public string $accessories = '';

    public string $intake_condition = '';

    public string $problem = '';

    public string $diagnosis = '';

    public string $work_done = '';

    public string $parts_used = '';

    public string $coverage = 'paid';

    public string $warranty_reference = '';

    /**
     * Whole soʻm ('' = none). Filled only for users with repairs.costs.
     */
    public string $estimate_amount = '';

    public string $final_amount = '';

    /**
     * Engineer on create: '' = unassigned, else a user id.
     */
    public string $assigned_to_id = '';

    public function setRepair(Repair $repair, User $user): void
    {
        $this->contact_name = $repair->contact_name;
        $this->locale = $repair->locale;
        $this->contact_phone = Phone::display($repair->contact_phone);
        $this->company = (string) $repair->company;
        $this->customer_id = (string) $repair->customer_id;
        $this->contact_id = (string) $repair->contact_id;
        $this->device_type = $repair->device_type->value;
        $this->device_brand = (string) $repair->device_brand;
        $this->device_model = (string) $repair->device_model;
        $this->serial_number = (string) $repair->serial_number;
        $this->accessories = (string) $repair->accessories;
        $this->intake_condition = $repair->intake_condition;
        $this->problem = $repair->problem;
        $this->diagnosis = (string) $repair->diagnosis;
        $this->work_done = (string) $repair->work_done;
        $this->parts_used = (string) $repair->parts_used;
        $this->coverage = $repair->coverage->value;
        $this->warranty_reference = (string) $repair->warranty_reference;

        if ($user->can('editCosts', $repair)) {
            $this->estimate_amount = Money::tiyinToSums($repair->estimate_amount);
            $this->final_amount = Money::tiyinToSums($repair->final_amount);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $costs = (bool) $this->component->canCosts;
        $amount = ['nullable', 'string', 'max:24', function (string $attribute, mixed $value, Closure $fail): void {
            $tiyin = filled($value) ? Money::sumsToTiyin((string) $value) : null;

            if (filled($value) && ($tiyin === null || $tiyin > Repair::AMOUNT_MAX_SUMS * 100)) {
                $fail(__('repairs.errors.amount_invalid'));
            }
        }];

        return [
            'locale' => ['required', Rule::in(config('app.supported_locales'))],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:32', new PhoneNumber],
            'company' => ['nullable', 'string', 'max:160'],
            'customer_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'device_type' => ['required', Rule::enum(RepairDeviceType::class)],
            'device_brand' => ['nullable', 'string', 'max:80'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'accessories' => ['nullable', 'string', 'max:500'],
            'intake_condition' => ['required', 'string', 'max:'.Repair::TEXT_MAX],
            'problem' => ['required', 'string', 'max:'.Repair::TEXT_MAX],
            'diagnosis' => ['nullable', 'string', 'max:'.Repair::TEXT_MAX],
            'work_done' => ['nullable', 'string', 'max:'.Repair::TEXT_MAX],
            'parts_used' => ['nullable', 'string', 'max:'.Repair::TEXT_MAX],
            'coverage' => ['required', Rule::enum(RepairCoverage::class)],
            'warranty_reference' => ['nullable', 'string', 'max:120', Rule::requiredIf($this->coverage === RepairCoverage::Warranty->value)],
            'estimate_amount' => $costs ? $amount : ['prohibited'],
            'final_amount' => $costs ? $amount : ['prohibited'],
            'assigned_to_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach (array_keys($this->all()) as $field) {
            $attributes[$field] = __('repairs.fields.'.$field);
        }

        return $attributes;
    }

    /**
     * Input for CreateRepair / UpdateRepair.
     */
    public function repairData(bool $costs): RepairData
    {
        return new RepairData(
            contactName: $this->contact_name,
            contactPhone: $this->contact_phone,
            deviceType: RepairDeviceType::from($this->device_type),
            intakeCondition: $this->intake_condition,
            problem: $this->problem,
            coverage: RepairCoverage::from($this->coverage),
            company: $this->company,
            customerId: ctype_digit($this->customer_id) ? (int) $this->customer_id : null,
            contactId: ctype_digit($this->contact_id) ? (int) $this->contact_id : null,
            deviceBrand: $this->device_brand,
            deviceModel: $this->device_model,
            serialNumber: $this->serial_number,
            accessories: $this->accessories,
            diagnosis: $this->diagnosis,
            workDone: $this->work_done,
            partsUsed: $this->parts_used,
            warrantyReference: $this->warranty_reference,
            costsIncluded: $costs,
            estimateAmount: $costs ? Money::sumsToTiyin($this->estimate_amount) : null,
            finalAmount: $costs ? Money::sumsToTiyin($this->final_amount) : null,
            locale: $this->locale,
            assigneeId: ctype_digit($this->assigned_to_id) ? (int) $this->assigned_to_id : null,
        );
    }
}
