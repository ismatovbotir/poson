<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Enums\PreferredContact;
use App\Listeners\SendLeadToTelegram;
use App\Livewire\Site\LeadForm;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

function siteLeadData(array $overrides = []): SiteLeadData
{
    return new SiteLeadData(...array_merge([
        'name' => 'Aziz',
        'phone' => '+998901234567',
        'company' => null,
        'message' => 'Does not scan',
        'locale' => 'uz',
    ], $overrides));
}

it('lets an explicit server-set service request win over the product rule', function () {
    $product = Product::factory()->create();

    app(SubmitSiteLead::class)->handle(siteLeadData(['type' => LeadType::ServiceRequest, 'productId' => $product->id, 'productSku' => $product->sku]));

    $lead = Lead::query()->with('items')->sole();

    expect($lead->type)->toBe(LeadType::ServiceRequest)
        ->and(SendLeadToTelegram::chatFor($lead))->toBe('service')
        ->and($lead->items)->toHaveCount(1);
});

it('makes a service product enquiry from the lead form a service request', function () {
    $product = Product::factory()->service()->create();

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])
        ->set('name', 'Aziz')->set('phone', '90 123 45 67');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertSet('sent', true);

    $lead = Lead::query()->sole();

    expect($lead->type)->toBe(LeadType::ServiceRequest)
        ->and(SendLeadToTelegram::chatFor($lead))->toBe('service');
});

it('keeps a hardware product enquiry a quote and a product-less one a callback', function () {
    $product = Product::factory()->create();

    app(SubmitSiteLead::class)->handle(siteLeadData(['productId' => $product->id]));
    app(SubmitSiteLead::class)->handle(siteLeadData(['phone' => '+998901234568']));

    $quote = Lead::query()->where('phone', '+998901234567')->sole();
    $callback = Lead::query()->where('phone', '+998901234568')->sole();

    expect($quote->type)->toBe(LeadType::Quote)
        ->and(SendLeadToTelegram::chatFor($quote))->toBe('sales')
        ->and($callback->type)->toBe(LeadType::Callback);
});

it('stores the cleaned device fields and the preferred contact, and logs none of them', function () {
    Log::spy();

    app(SubmitSiteLead::class)->handle(siteLeadData([
        'type' => LeadType::ServiceRequest,
        'deviceBrand' => "  Zebra\n\u{202E}  ",
        'deviceModel' => str_repeat('M', 200),
        'serialNumber' => 'SN-123',
        'preferredContact' => PreferredContact::Telegram,
    ]));

    $lead = Lead::query()->sole();

    expect($lead->device_brand)->toBe('Zebra')
        ->and($lead->device_model)->toHaveLength(120)
        ->and($lead->serial_number)->toBe('SN-123')
        ->and($lead->preferred_contact)->toBe(PreferredContact::Telegram);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => array_keys($context) === ['lead_id', 'locale', 'source', 'type']);
});

it('rejects an unknown preferred contact value', function () {
    expect(PreferredContact::tryFrom('email'))->toBeNull()
        ->and(PreferredContact::tryFrom('call'))->toBe(PreferredContact::Call)
        ->and(PreferredContact::Telegram->label())->not->toStartWith('enums.');
});

it('does not set the new columns on a lead that has none', function () {
    app(SubmitSiteLead::class)->handle(siteLeadData());

    $lead = Lead::query()->sole();

    expect($lead->device_brand)->toBeNull()
        ->and($lead->serial_number)->toBeNull()
        ->and($lead->preferred_contact)->toBeNull();
});
