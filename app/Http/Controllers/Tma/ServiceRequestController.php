<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\PreferredContact;
use App\Models\Product;
use App\Support\Catalog\ServiceableDevice;
use App\Support\Leads\LeadLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Service for this device" in the Mini App (plan 20 §6.3 Q4, M4; MA-19 =
 * plan 36 P36-1, P36-2, P36-6, P36-7): a short form for a visible,
 * serviceable hardware product (else 404, checked again on submit). The
 * device brand and model come from the product on the server; the customer
 * types the problem (10–2000), an optional serial number (P36-6 pattern, no
 * line breaks) and the preferred contact. Name and phone from the bot
 * registration only. Always a service_request lead (server-set), source
 * Telegram, to the service chat. Budgets: "tma-service" PER_USER per hour
 * and the shared per-phone "site-lead-phone:" budget; last the public phone
 * rules and the foreign-phone cap (counted only for a written lead).
 */
class ServiceRequestController extends TmaController
{
    public const PER_USER = 5;

    public const SERIAL_PATTERN = '/^[A-Za-z0-9\/. -]{1,64}$/D';

    public function show(Request $request, Product $product): Response
    {
        $this->ensureServiceable($product);

        return $this->page($request, 'tma.service', [
            'title' => __('tma.service.title'),
            'back' => route('tma.product', ['product' => $product->slug]),
            'product' => $product,
            'device' => $this->device($product),
            'preferredContacts' => PreferredContact::cases(),
        ]);
    }

    public function store(Request $request, Product $product, SubmitSiteLead $submitSiteLead): RedirectResponse
    {
        $this->ensureServiceable($product);

        $user = $this->user($request);
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->phoneScreen();
        }

        $form = route('tma.service', ['product' => $product->slug]);
        $validator = Validator::make([
            'message' => is_string($request->input('message')) ? trim($request->input('message')) : $request->input('message'),
            'serial_number' => is_string($request->input('serial_number')) ? trim($request->input('serial_number')) : $request->input('serial_number'),
            'preferred_contact' => $request->input('preferred_contact'),
        ], [
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'serial_number' => ['nullable', 'string', 'regex:'.self::SERIAL_PATTERN],
            'preferred_contact' => ['required', Rule::enum(PreferredContact::class)],
        ], [], [
            'message' => __('tma.service.fields.message'),
            'serial_number' => __('tma.service.fields.serial_number'),
            'preferred_contact' => __('tma.service.fields.preferred_contact'),
        ]);

        if ($validator->fails()) {
            return redirect($form)->withErrors($validator)->withInput($request->only('message', 'serial_number', 'preferred_contact'));
        }

        $validated = $validator->validated();

        if ($this->overBudget('tma-service:'.$user->id, self::PER_USER, 3600) || LeadLimits::phoneExceeded($phone)) {
            return redirect($form)->withErrors(['form' => __('tma.request.errors.rate_limited')])->withInput($request->only('message', 'serial_number', 'preferred_contact'));
        }

        if (($refusal = $this->phoneRefusal($phone)) !== null) {
            return redirect($form)->withErrors(['form' => $refusal])->withInput($request->only('message', 'serial_number', 'preferred_contact'));
        }

        $device = $this->device($product);

        $submitSiteLead->handle(new SiteLeadData(
            name: $this->requestName($user),
            phone: $phone,
            company: filled($user->declared_company) ? mb_substr((string) $user->declared_company, 0, 160) : null,
            message: $validated['message'],
            locale: app()->getLocale(),
            source: LeadSource::Telegram,
            type: LeadType::ServiceRequest,
            productId: $product->id,
            productSku: $product->sku,
            deviceBrand: $device['brand'],
            deviceModel: $device['model'],
            serialNumber: filled($validated['serial_number'] ?? null) ? $validated['serial_number'] : null,
            preferredContact: PreferredContact::from($validated['preferred_contact']),
        ));

        $this->recordPhone($phone);

        return redirect()->route('tma.product', ['product' => $product->slug])->with('tma_status', 'service_requested');
    }

    protected function ensureServiceable(Product $product): void
    {
        $product->loadMissing('brand');

        abort_unless($product->isVisible() && ServiceableDevice::for($product) !== null, 404);
    }

    /**
     * @return array{brand: ?string, model: string}
     */
    protected function device(Product $product): array
    {
        $brand = mb_substr((string) $product->publicBrand()?->name, 0, 80);

        return [
            'brand' => $brand !== '' ? $brand : null,
            'model' => mb_substr((string) (filled($product->model) ? $product->model : $product->name), 0, 120),
        ];
    }
}
