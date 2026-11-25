<?php

use App\Livewire\Site\Cart\QuoteForm;
use App\Livewire\Site\LeadForm;
use App\Models\Cart;
use App\Support\Cart\CartToken;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Shared helpers of the cart page and cart quote form tests (plan 11a; QuoteForm since plan 42).
 */

/**
 * A cart of the current visitor (its token remembered for this test's requests).
 */
function visitorCart(): Cart
{
    $cart = Cart::factory()->create();
    app(CartToken::class)->issue($cart->token);

    return $cart;
}

/**
 * The cart quote form (Site\Cart\QuoteForm), opened long enough ago to pass the fill-time check.
 */
function filledCartQuote(array $overrides = []): Testable
{
    $component = Livewire::test(QuoteForm::class);

    foreach (array_merge(['name' => 'Aziz', 'phone' => '90 123 45 67', 'company' => 'OOO Market'], $overrides) as $field => $value) {
        $component->set($field, $value);
    }

    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}
