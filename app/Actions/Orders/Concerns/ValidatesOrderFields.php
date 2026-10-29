<?php

namespace App\Actions\Orders\Concerns;

use App\Data\Orders\OrderDeliveryData;
use App\Data\Orders\OrderDetailsData;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\Region;
use App\Enums\Role;
use App\Models\User;
use App\Rules\UzPhone;
use App\Support\Orders\TaxId;
use App\Support\Phone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation of the buyer and delivery fields staff type on an order
 * (enums with tryFrom, UzPhone, STIR / PINFL per buyer type, lengths).
 */
trait ValidatesOrderFields
{
    /**
     * @return array{name: string, phone: string, buyer_type: BuyerType, company_name: string|null, tin: string|null, comment: string|null, staff_note: string|null, manager_id: int|null}
     *
     * @throws ValidationException
     */
    protected function validatedDetails(OrderDetailsData $data): array
    {
        $buyerType = BuyerType::tryFrom($data->buyerType);
        $tin = TaxId::normalize($data->tin);

        $validator = Validator::make([
            'name' => trim($data->name),
            'phone' => $data->phone,
            'buyer_type' => $data->buyerType,
            'company_name' => trim($data->companyName),
            'tin' => $tin,
            'comment' => trim($data->comment),
            'staff_note' => trim($data->staffNote),
        ], [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new UzPhone],
            'buyer_type' => ['required', Rule::enum(BuyerType::class)],
            'company_name' => [$buyerType?->isBusiness() ? 'required' : 'nullable', 'string', 'max:160'],
            'tin' => [$buyerType?->isBusiness() ? 'required' : 'nullable', 'string', 'max:14'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'staff_note' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'name' => __('orders.fields.name'),
            'phone' => __('orders.fields.phone'),
            'company_name' => __('orders.fields.company_name'),
            'tin' => __('orders.fields.tin'),
        ]);

        $validator->after(function ($validator) use ($buyerType, $tin): void {
            if ($buyerType !== null && $buyerType->isBusiness() && $tin !== '' && ! TaxId::isValid($buyerType, $tin)) {
                $validator->errors()->add('tin', __(TaxId::messageKey($buyerType)));
            }
        });

        $validator->validate();

        /** @var BuyerType $buyerType */
        $managerId = null;

        if ($data->managerId !== null) {
            $manager = User::query()->whereKey($data->managerId)->where('is_active', true)->first();

            if (! $manager instanceof User || ! $manager->hasAnyRole([Role::Admin->value, Role::SalesManager->value])) {
                throw ValidationException::withMessages(['manager_id' => __('orders.errors.manager_invalid')]);
            }

            $managerId = $manager->id;
        }

        return [
            'name' => mb_substr(trim($data->name), 0, 120),
            'phone' => (string) Phone::normalize($data->phone),
            'buyer_type' => $buyerType,
            'company_name' => $buyerType->isBusiness() ? mb_substr(trim($data->companyName), 0, 160) : null,
            'tin' => $buyerType->isBusiness() ? $tin : null,
            'comment' => trim($data->comment) !== '' ? mb_substr(trim($data->comment), 0, 1000) : null,
            'staff_note' => trim($data->staffNote) !== '' ? mb_substr(trim($data->staffNote), 0, 2000) : null,
            'manager_id' => $managerId,
        ];
    }

    /**
     * @return array{delivery_type: DeliveryType, region: Region|null, address: string|null, kokand_address_confirmed: bool}
     *
     * @throws ValidationException
     */
    protected function validatedDelivery(OrderDeliveryData $data): array
    {
        $type = DeliveryType::tryFrom($data->deliveryType);

        Validator::make([
            'delivery_type' => $data->deliveryType,
            'region' => $data->region,
            'address' => trim($data->address),
        ], [
            'delivery_type' => ['required', Rule::enum(DeliveryType::class)],
            'region' => [$type === DeliveryType::CourierRegion ? 'required' : 'nullable', Rule::enum(Region::class)],
            'address' => [$type?->needsAddress() ? 'required' : 'nullable', 'string', 'max:500'],
        ], [], [
            'region' => __('orders.fields.region'),
            'address' => __('orders.fields.address'),
        ])->validate();

        /** @var DeliveryType $type */
        return [
            'delivery_type' => $type,
            'region' => $type === DeliveryType::CourierRegion ? Region::tryFrom($data->region) : null,
            'address' => $type->needsAddress() ? mb_substr(trim($data->address), 0, 500) : null,
            'kokand_address_confirmed' => $type === DeliveryType::CourierKokand && $data->kokandConfirmed,
        ];
    }
}
