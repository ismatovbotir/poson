<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use Illuminate\Support\Facades\Log;

it('logs a lead reference and the locale only', function () {
    Log::spy();

    $reference = app(SubmitSiteLead::class)->handle(new SiteLeadData(
        name: 'Aziz Karimov',
        phone: '+998901234567',
        company: 'OOO Market',
        message: 'Need labels',
        locale: 'uz',
        utm: ['utm_source' => 'telegram'],
        pageUrl: 'https://poson.uz/uz',
    ));

    expect($reference)->toStartWith('L-');

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context === [
            'reference' => $reference,
            'locale' => 'uz',
            'source' => 'site',
            'type' => 'callback',
        ]);
});

it('logs the product SKU of a quote request, without personal data', function () {
    Log::spy();

    $reference = app(SubmitSiteLead::class)->handle(new SiteLeadData(
        name: 'Aziz Karimov',
        phone: '+998901234567',
        company: null,
        message: null,
        locale: 'ru',
        type: LeadType::Quote,
        productId: 7,
        productSku: 'ZEB-DS2208',
    ));

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context === [
            'reference' => $reference,
            'locale' => 'ru',
            'source' => 'site',
            'type' => 'quote',
            'product_sku' => 'ZEB-DS2208',
        ]);
});
