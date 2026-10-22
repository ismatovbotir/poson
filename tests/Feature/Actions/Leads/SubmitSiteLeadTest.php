<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Enums\ProductType;
use App\Enums\TrafficChannel;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

it('creates a site lead with the attribution and returns its reference', function () {
    $reference = app(SubmitSiteLead::class)->handle(new SiteLeadData(
        name: 'Aziz Karimov',
        phone: '+998901234567',
        company: 'OOO Market',
        message: 'Need labels',
        locale: 'ru',
        utm: ['utm_source' => 'telegram'],
        pageUrl: 'https://poson.uz/ru',
        trafficChannel: TrafficChannel::Campaign,
        trafficSource: 'telegram',
        landingPath: '/ru/catalog',
    ));

    $lead = Lead::query()->sole();

    expect($reference)->toBe('L-'.$lead->id)
        ->and($lead->source)->toBe(LeadSource::Site)
        ->and($lead->type)->toBe(LeadType::Callback)
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->name)->toBe('Aziz Karimov')
        ->and($lead->phone)->toBe('+998901234567')
        ->and($lead->company)->toBe('OOO Market')
        ->and($lead->message)->toBe('Need labels')
        ->and($lead->locale)->toBe('ru')
        ->and($lead->utm)->toBe(['utm_source' => 'telegram'])
        ->and($lead->page_url)->toBe('https://poson.uz/ru')
        ->and($lead->traffic_channel)->toBe(TrafficChannel::Campaign)
        ->and($lead->traffic_source)->toBe('telegram')
        ->and($lead->landing_path)->toBe('/ru/catalog')
        ->and($lead->created_by_id)->toBeNull();
});

it('logs the lead id, type and source only', function () {
    Log::spy();

    app(SubmitSiteLead::class)->handle(new SiteLeadData(
        name: 'Aziz Karimov',
        phone: '+998901234567',
        company: 'OOO Market',
        message: 'Need labels',
        locale: 'uz',
        utm: ['utm_source' => 'telegram'],
        pageUrl: 'https://poson.uz/uz?phone=998901234567',
    ));

    $lead = Lead::query()->sole();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context === [
            'lead_id' => $lead->id,
            'locale' => 'uz',
            'source' => 'site',
            'type' => 'callback',
        ]);
});

it('turns a product enquiry into a quote with the product as an item, service products too', function (ProductType $type) {
    Log::spy();
    $product = Product::factory()->create(['type' => $type, 'sku' => 'ZEB-DS2208', 'name' => ['uz' => 'Skaner', 'ru' => 'Сканер', 'en' => 'Scanner']]);

    app(SubmitSiteLead::class)->handle(new SiteLeadData(
        name: 'Aziz Karimov',
        phone: '+998901234567',
        company: null,
        message: null,
        locale: 'ru',
        type: LeadType::ServiceRequest,
        productId: $product->id,
        productSku: $product->sku,
    ));

    $lead = Lead::query()->with('items')->sole();

    expect($lead->type)->toBe(LeadType::Quote)
        ->and($lead->items)->toHaveCount(1)
        ->and($lead->items[0]->product_id)->toBe($product->id)
        ->and($lead->items[0]->sku)->toBe('ZEB-DS2208')
        ->and($lead->items[0]->product_name)->toBe('Сканер')
        ->and($lead->items[0]->qty)->toBe(1);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context === [
            'lead_id' => $lead->id,
            'locale' => 'ru',
            'source' => 'site',
            'type' => 'quote',
            'product_sku' => 'ZEB-DS2208',
        ]);
})->with([ProductType::Hardware, ProductType::Service]);
