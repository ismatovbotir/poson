<?php

use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\KnowledgeText;

/**
 * Plan 33 A2 fix round (§19): the company's own payment-method text is
 * delivered, and the review's missed payment, submission, Click, VAT and
 * card strings are blocked.
 */
function a2FixFilter(): AiOutputFilter
{
    return new AiOutputFilter(['poson.uz', 'www.poson.uz'], ['+998 90 123 45 67'], ['info@poson.uz']);
}

it('delivers the company payment-method sentences (H-1)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBeNull()
        ->and(a2FixFilter()->checkWithoutMoney($text))->toBeNull();
})->with('a2fix own payment methods');

it('still blocks a payment-method noun aimed at a card or a person (H-1 boundary)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2fix method noun aimed at a card or person');

it('blocks the missed submission claims and contact questions (M-1)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2fix submission claims');

it('keeps flow descriptions and model or serial number requests (M-1 false positives)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBeNull();
})->with('a2fix submission false positives');

it('blocks paying a person and handing money over (M-2)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2fix pay a person');

it('blocks Click with Uzbek suffixes, the klik spelling and Paynet (M-3)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2fix click suffixes');

it('gives no VAT exemption next to promo or saving words (M-4, decimal-point nit)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBe('brand')
        ->and(AiOutputFilter::vatRateSpans(mb_strtolower($text)))->toBe([]);
})->with('a2fix vat promo');

it('masks a VAT rate next to a promo word in knowledge text like any percentage (M-4)', function () {
    expect(KnowledgeText::maskPercents('QQS 12% bonus', '[X]'))->toBe('QQS [X] bonus')
        ->and(KnowledgeText::maskPercents('Narxlar 12% QQS bilan', '[X]'))->toBe('Narxlar 12% QQS bilan');
});

it('does not split a sentence at a decimal point (code-review nit)', function () {
    expect(AiOutputFilter::vatRateSpans('lenta 3.5 mm. 12% qqs bilan'))->toBe([['start' => 14, 'length' => 3]])
        ->and(AiOutputFilter::vatRateSpans('save on the 3.5 mm ribbon with 12% qqs'))->toBe([]);
});

it('blocks a card number with spaced emoji separators (Low)', function () {
    expect(a2FixFilter()->apply('8600 💳 1234 💳 5678 💳 9012')['blocked'])->toBe('card');
});

it('keeps warranty-card sentences (Low)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBeNull();
})->with('a2fix warranty cards');

it('checks soft rule labels against the AI brand list too (Low)', function () {
    expect(AiOutputFilter::violatesBrand('Optom narxlar'))->toBeTrue()
        ->and(AiOutputFilter::violatesBrand('ch.e.g.i.r.m.a'))->toBeTrue()
        ->and(AiOutputFilter::violatesBrand('Tijorat taklifi'))->toBeFalse();
});

it('blocks a price list for dealers or wholesale (A2-S15 completion)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBe('brand');
})->with([
    'price list for dealers' => ['Our price list for dealers'],
    'прайс-лист для дилеров' => ['Прайс-лист для дилеров'],
    'прайс для оптовиков' => ['Прайс для оптовиков'],
]);

it('blocks method nouns aimed at a card, cash handed to staff and courier cash with a card (§20 M-5 – M-7)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBe('contact');
})->with('a2fix20 blocked');

it('delivers cash on delivery to the courier and plain method lines (§20 M-7)', function (string $text) {
    expect(a2FixFilter()->apply($text)['blocked'])->toBeNull();
})->with('a2fix20 delivered');
