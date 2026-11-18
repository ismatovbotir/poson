<?php

namespace App\Livewire\Site\Services;

use App\Actions\Analytics\RecordConversion;
use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Enums\PreferredContact;
use App\Livewire\Site\Concerns\GuardsLeadSubmissions;
use App\Models\Product;
use App\Rules\PhoneNumber;
use App\Support\Analytics\Attribution;
use App\Support\Catalog\ServiceableDevice;
use App\Support\Phone;
use App\Support\Site\PageDirectory;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Request repair / service" at /{locale}/services/request (plan 36, I2).
 *
 * Creates a service_request lead (type set by the server, never from the
 * browser) with the device brand, model, serial number and the preferred
 * way of contact. Spam checks are LeadForm::submit's, in the same order and
 * with the same limiter budgets (GuardsLeadSubmissions, App\Livewire\Site\LeadForm).
 *
 * "?product={slug}" prefills the device from a visible, serviceable hardware
 * product (Product::visible() + ServiceableDevice::for()) and locks its id;
 * anything else is ignored silently and never echoed. The product is
 * checked again on submit; if it fails the request goes without an item.
 * The page's title, description and canonical never depend on the query,
 * and a page with a query is noindex,follow.
 */
class RequestPage extends Component
{
    use GuardsLeadSubmissions;

    public string $name = '';

    public string $phone = '';

    public string $company = '';

    public string $device_brand = '';

    public string $device_model = '';

    public string $serial_number = '';

    public string $message = '';

    public string $preferred_contact = 'call';

    /**
     * Honeypot: hidden from people, bots fill it in.
     */
    public string $fax_ext = '';

    public bool $sent = false;

    #[Locked]
    public string $locale = '';

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public ?string $pageUrl = null;

    /**
     * @var array<string, string>
     */
    #[Locked]
    public array $attribution = [];

    #[Locked]
    public int $renderedAt = 0;

    public function mount(): void
    {
        $slug = request()->query('product');
        $product = is_string($slug) && $slug !== '' && mb_strlen($slug) <= 191 ? $this->serviceableProduct(slug: $slug) : null;

        if ($product !== null) {
            $this->productId = $product->id;
            $this->device_brand = mb_substr((string) $product->publicBrand()?->name, 0, 80);
            $this->device_model = mb_substr((string) (filled($product->model) ? $product->model : $product->name), 0, 120);
        }

        $this->locale = app()->getLocale();
        $this->pageUrl = url()->current();
        $this->attribution = $this->captureAttribution();
        $this->renderedAt = now()->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new PhoneNumber(public: true)],
            'company' => ['nullable', 'string', 'max:160'],
            'device_brand' => ['required', 'string', 'max:80', 'not_regex:/[\r\n]/'],
            'device_model' => ['required', 'string', 'max:120', 'not_regex:/[\r\n]/'],
            'serial_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9\/. -]{1,64}$/D'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'preferred_contact' => ['required', Rule::enum(PreferredContact::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return collect(['name', 'phone', 'company', 'device_brand', 'device_model', 'serial_number', 'message', 'preferred_contact'])
            ->mapWithKeys(fn (string $field): array => [$field => __("site.services.request.attributes.{$field}")])
            ->all();
    }

    public function submit(SubmitSiteLead $submitSiteLead, RecordConversion $recordConversion): void
    {
        $validated = $this->validate();
        $phone = Phone::normalizeAny($validated['phone']);

        if ($this->leadLimitsExceeded($phone)) {
            return;
        }

        if ($this->foreignPhoneCapExceeded($phone)) {
            return;
        }

        // Bots get the same success screen, but nothing is submitted.
        if (! $this->looksLikeBot()) {
            // Checked again: a product hidden since mount goes without an item (P36-7).
            $product = $this->productId !== null ? $this->serviceableProduct(id: $this->productId) : null;

            $submitSiteLead->handle(new SiteLeadData(
                name: trim($validated['name']),
                phone: $phone,
                company: filled($validated['company']) ? trim($validated['company']) : null,
                message: trim($validated['message']),
                locale: $this->locale,
                type: LeadType::ServiceRequest,
                utm: Attribution::utm($this->attribution),
                pageUrl: $this->pageUrl,
                productId: $product?->id,
                productSku: $product?->sku,
                trafficChannel: Attribution::channel($this->attribution),
                trafficSource: $this->attribution['source'] ?? null,
                landingPath: $this->attribution['landing'] ?? null,
                deviceBrand: trim($validated['device_brand']),
                deviceModel: trim($validated['device_model']),
                serialNumber: filled($validated['serial_number']) ? trim($validated['serial_number']) : null,
                preferredContact: PreferredContact::from($validated['preferred_contact']),
            ));

            $recordConversion->handle(request(), $this->attribution, $this->pageUrl, $this->locale, $product?->id);
        }

        $this->reset('name', 'phone', 'company', 'device_brand', 'device_model', 'serial_number', 'message', 'fax_ext');
        $this->sent = true;
    }

    public function render(StructuredData $structuredData): View
    {
        $url = route('site.services.request', ['locale' => app()->getLocale()]);
        $warranty = PageDirectory::isActive('warranty-service');
        $breadcrumbs = array_values(array_filter([
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            $warranty ? ['label' => PageDirectory::title('warranty-service'), 'url' => route('site.page', ['page' => 'warranty-service'])] : null,
            ['label' => __('site.services.request.title'), 'url' => null],
        ]));

        return view('livewire.site.services.request-page', [
            'breadcrumbs' => $breadcrumbs,
            'jsonLd' => [
                StructuredData::toScript($structuredData->webPage('WebPage', __('site.services.request.title'), $url, __('site.services.request.meta_description'))),
                StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url)),
            ],
            'preferredContacts' => PreferredContact::cases(),
        ])
            ->title(__('site.services.request.meta_title'))
            ->layoutData([
                'description' => __('site.services.request.meta_description'),
                // A query ("?product=") makes the page a duplicate of the clean one (LOW-10).
                'robots' => request()->query() !== [] ? 'noindex,follow' : null,
            ]);
    }

    /**
     * A visible, serviceable hardware product by slug or id, else null.
     */
    protected function serviceableProduct(?string $slug = null, ?int $id = null): ?Product
    {
        $product = Product::query()
            ->visible()
            ->with('brand')
            ->when($slug !== null, fn ($query) => $query->where('products.slug', $slug))
            ->when($id !== null, fn ($query) => $query->whereKey($id))
            ->first();

        return $product instanceof Product && ServiceableDevice::for($product) !== null ? $product : null;
    }
}
