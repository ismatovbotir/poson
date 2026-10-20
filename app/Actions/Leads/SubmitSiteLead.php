<?php

namespace App\Actions\Leads;

use App\Data\Leads\SiteLeadData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Accept a lead from the public site form.
 *
 * TODO(CRM increment): persist the lead (leads table, customer match by phone),
 * dispatch LeadCreated and send the Telegram notification to the sales chat.
 * Until then the lead is only logged, without any personal data.
 */
class SubmitSiteLead
{
    /**
     * @return string Lead reference
     */
    public function handle(SiteLeadData $data): string
    {
        $reference = 'L-'.Str::upper(Str::random(10));

        // Only the reference and channel: no name, phone, company, message or query-string data in logs.
        Log::info('Site lead received', [
            'reference' => $reference,
            'locale' => $data->locale,
            'source' => $data->source->value,
            'type' => $data->type->value,
        ]);

        return $reference;
    }
}
