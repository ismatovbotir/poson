<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Delete a lead for good (Admin, leads.delete): its activities go too (a
 * morph has no foreign key), its items through the cascade; leads that
 * pointed to it as their original lose the duplicate flag (nullOnDelete).
 */
class DeleteLead
{
    public function handle(Lead $lead): void
    {
        DB::transaction(function () use ($lead): void {
            $lead->activities()->delete();
            $lead->delete();
        });
    }
}
