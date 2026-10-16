---
name: cart-checkout
description: Cart and checkout flow for products with sale_mode cart/both (mostly consumables, some hardware/software). Covers session/DB cart, quantity rules (min_qty), checkout form (individual or company with INN), delivery/pickup, payment method choice (Payme, Click, cash/bank transfer invoice), orders and order statuses. Use when building cart, checkout or order management.
---

# Cart + checkout

## Two flows

1. **Cart**: products with `sale_mode` cart or both and a non-null price, leading to checkout and then an order and payment.
2. **Quote**: everything else goes through the quote request (see `crm-leads-quotes`).
   The cart page also offers "Request a quote for this cart", which turns the cart into a lead.

## Cart

- A `CartService` is backed by a `carts` table (`token` cookie, nullable `user_id`) plus `cart_items` (product_id, qty). This lets an abandoned cart survive sessions.
- Quantity must be ≥ `min_qty`. Inactive, quote-only and price-less products are rejected.
- Prices are always re-read from the product. The cart stores no prices.
- The header counter is a Livewire component that listens for the `cart-updated` event.

## Orders

- `orders`
  - Identity and customer: id, number (`O-2026-0001`), source (site/quote/manual), customer_id (nullable), name, phone, email, company, inn
  - Delivery: delivery_type (pickup/courier/regions), address, comment
  - Payment: payment_method (enum: payme/click/cash/bank_transfer), payment_status (unpaid/pending/paid/refunded)
  - Totals and status: status (OrderStatus), subtotal, delivery_amount, total (tiyin), locale, paid_at, timestamps
- `order_items`: snapshot of name, sku, price, qty and total
- `OrderStatus`: new → confirmed → processing → shipped → completed | cancelled

## Checkout

- `PlaceOrder` runs inside a DB transaction. It recalculates totals, creates the order and items, clears the cart, and dispatches `OrderPlaced` (which notifies Telegram).
- With **payme** or **click**, the customer is redirected to the provider's checkout URL (see the payment skills). The order is only marked paid through the **webhook**, never from the return URL.
- **bank_transfer** generates an invoice PDF. Payment is confirmed manually by a sales manager.
- Rate-limit checkout. Validate phones as `+998`.
