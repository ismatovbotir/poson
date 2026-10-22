<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a customer for good (Admin, customers.delete). Blocked while any
 * lead links to it, so no history is lost silently; contacts go through the
 * cascade, its activities with it.
 */
class DeleteCustomer
{
    /**
     * @throws ValidationException
     */
    public function handle(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            // Lock the customer so no lead can be linked between the check and the delete.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $leads = Lead::query()->where('customer_id', $customer->id)->count();

            if ($leads > 0) {
                throw ValidationException::withMessages(['customer' => trans_choice('crm.customers.errors.has_leads', $leads, ['count' => $leads])]);
            }

            $customer->activities()->delete();
            $customer->delete();
        });
    }
}
