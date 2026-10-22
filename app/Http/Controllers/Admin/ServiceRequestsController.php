<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LeadType;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * "Servis soʻrovlari" (menu → Service): Murojaatlar filtered to service
 * requests (decision 2026-10-22), for users who see service requests.
 */
class ServiceRequestsController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        Gate::authorize('viewServiceRequests', Lead::class);

        return redirect()->route('admin.leads.index', ['type' => LeadType::ServiceRequest->value]);
    }
}
