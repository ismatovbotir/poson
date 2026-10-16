---
name: click-integration
description: Click (click.uz) SHOP API integration for paying orders. Covers Prepare (action=0) and Complete (action=1) callbacks, md5 sign_string verification, error codes, idempotency, checkout URL and tests. Use when implementing or debugging Click payments.
---

# Click SHOP API

> Verify details against the official docs (docs.click.uz) before going live.

## Config

```php
'click' => [
    'service_id'  => env('CLICK_SERVICE_ID'),
    'merchant_id' => env('CLICK_MERCHANT_ID'),
    'merchant_user_id' => env('CLICK_MERCHANT_USER_ID'),
    'secret_key'  => env('CLICK_SECRET_KEY'),
],
```

## Checkout redirect

`https://my.click.uz/services/pay?service_id=..&merchant_id=..&amount={sum with 2 decimals}&transaction_param={order_id}&return_url=..`
Click amounts are in **sum** (not tiyin), so convert with `Money::toSum()`.

## Endpoints (form-encoded POST, CSRF-excluded)

- `POST /webhooks/click/prepare` (action = 0)
- `POST /webhooks/click/complete` (action = 1)

## Signature

- Prepare: `md5(click_trans_id . service_id . SECRET_KEY . merchant_trans_id . amount . action . sign_time)`
- Complete: `md5(click_trans_id . service_id . SECRET_KEY . merchant_trans_id . merchant_prepare_id . amount . action . sign_time)`

Compare with `hash_equals`. `merchant_trans_id` is our order id.

## Error codes (returned as `error` + `error_note`)

| code | meaning |
|---|---|
| 0 | Success |
| -1 | SIGN CHECK FAILED |
| -2 | Incorrect amount |
| -3 | Action not found |
| -4 | Already paid |
| -5 | Order (user) not found |
| -6 | Transaction not found |
| -7 | Failed to update |
| -8 | Error in request from Click |
| -9 | Transaction cancelled |

## Table `click_transactions`

click_trans_id (unique), click_paydoc_id, order_id, amount, status (prepared/completed/cancelled), error, sign_time, timestamps. `id` serves as `merchant_prepare_id`.

## Logic

- **Prepare**: validate the signature, the order, that the order is unpaid and that the amount matches. Create a `prepared` row and return `merchant_prepare_id`.
- **Complete**: validate the signature and that the prepare row exists (else `-6`). If Click sends `error < 0`, mark the row cancelled and return `-9`. If already completed, return `-4`. Otherwise mark the order paid **in a DB transaction with locks**, set the row to completed and return `merchant_confirm_id`.
- Responses echo `click_trans_id` and `merchant_trans_id`. The JSON response always has HTTP 200.
- Dispatch `OrderPaid` after success. Log to the `payments` channel.
- Tests: valid flow, bad sign, wrong amount, double complete, complete without prepare, cancelled by Click.
