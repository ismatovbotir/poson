<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
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
