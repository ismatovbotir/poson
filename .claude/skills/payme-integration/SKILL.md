---
name: payme-integration
description: Payme (Paycom) Merchant API integration for paying orders. Covers JSON-RPC endpoint, Basic auth, the CheckPerformTransaction/CreateTransaction/PerformTransaction/CancelTransaction/CheckTransaction/GetStatement methods, transaction states, error codes, amounts in tiyin, checkout URL generation and tests. Use when implementing or debugging Payme.
---

# Payme Merchant API

> Verify details against the official docs (developer.help.paycom.uz) before going live. Test in the Payme sandbox (test.paycom.uz).

## Config

```php
// config/services.php
'payme' => [
    'merchant_id' => env('PAYME_MERCHANT_ID'),
    'key'         => env('PAYME_KEY'),          // test key in sandbox, prod key live
    'checkout_url'=> env('PAYME_CHECKOUT_URL', 'https://checkout.paycom.uz'),
    'account_field' => 'order_id',
],
```

## Checkout redirect

Use a GET URL: `{checkout_url}/` + base64(`m={merchant_id};ac.order_id={id};a={amount_tiyin};l={locale};c={return_url}`).
The `locale` is `uz`, `ru` or `en`. The amount is in **tiyin**, and `orders.total` is already in tiyin.

## Endpoint

- `POST /webhooks/payme` goes to `Webhooks\PaymeController` (excluded from CSRF, no session).
- **Auth**: header `Authorization: Basic base64("Paycom:{key}")`. If it's wrong, return error `-32504`.
- The request is JSON-RPC: `{ "method", "params", "id" }`. The response is **always HTTP 200** with `{ "result": ... }` or `{ "error": { "code", "message": { "uz", "ru", "en" }, "data" } }`, echoing `id`.
- Error messages are localized in uz, ru and en.

## Table `payme_transactions`

paycom_transaction_id (unique), paycom_time (bigint ms), order_id, amount (tiyin), state (int), reason (nullable int), create_time, perform_time, cancel_time (bigint ms, 0 if not set), timestamps.

## Methods (logic lives in `Services\Payments\Payme\PaymeMerchant`)

| Method | Logic |
|---|---|
| CheckPerformTransaction | Order exists (`-31050` if not), order unpaid and payable (`-31051`/`-31099` range for account errors), amount == order total (`-31001`) → `{ allow: true }` |
| CreateTransaction | If a txn with this id exists: state 1 and not timed out (12h = 43 200 000 ms) returns it; otherwise `-31008`. If another active txn exists for the order, return an account error (`-31050..-31099`). Create with state 1 and return `{ create_time, transaction, state }` |
| PerformTransaction | Not found → `-31003`. State 1: if timed out, cancel (state -1, reason 4) and return `-31008`; else mark the order paid **in a DB transaction**, set state 2 and perform_time. State 2: return the existing result. Otherwise `-31008` |
| CancelTransaction | Not found → `-31003`. State 1 → -1. State 2 → if the order can be refunded, -2 and the order is refunded, else `-31007`. Already cancelled → return the current state. Store `reason` |
| CheckTransaction | Return create/perform/cancel times, transaction, state and reason |
| GetStatement | Transactions with paycom_time between `from` and `to` |

## Rules

- Everything is idempotent. Repeated calls return the same result.
- Times are **milliseconds** (`now()->getTimestampMs()`).
- Use `lockForUpdate` on the order and the transaction row.
- Log every request and response to the `payments` log channel. Never log the auth header.
- After paying, dispatch `OrderPaid` (Telegram notification, order status `confirmed`).
- Tests: Pest feature tests for each method, wrong auth, wrong amount, timeout, double perform and cancel after perform.
