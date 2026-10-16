---
name: telegram-notifications
description: Sending business notifications to Telegram (new lead/quote request, new order, payment received, service request) via Bot API using queued Laravel notifications. Covers config, chat routing per event, message formatting, retries and tests with Http::fake. Use when adding or changing any Telegram notification.
---

# Telegram notifications

## Config

```php
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chats' => [
        'sales'   => env('TELEGRAM_SALES_CHAT_ID'),    // leads, quotes, orders
        'service' => env('TELEGRAM_SERVICE_CHAT_ID'),  // service requests
        'finance' => env('TELEGRAM_FINANCE_CHAT_ID'),  // payments
    ],
],
```

## Implementation

- `App\Services\Telegram\TelegramClient::sendMessage(string $chatId, string $html, array $buttons = [])` wraps `Http::post("https://api.telegram.org/bot{token}/sendMessage", [... 'parse_mode' => 'HTML'])` with `->retry(3, 500)->throw()`.
- Use a custom notification channel `TelegramChannel` plus a `toTelegram()` method, or queued jobs `SendTelegramMessage`. Pick one and keep it consistent.
- Event listeners (all `ShouldQueue`) map events to chats:

| Event | Chat |
|---|---|
| `LeadCreated` (source != service_request) | sales |
| `LeadCreated` (service_request) | service |
| `OrderPlaced` | sales |
| `OrderPaid` | sales + finance |

- Escape all user input with `e()` because the HTML parse mode breaks on `<`.
- Add an inline button "Open in admin" pointing to the admin URL.
- Messages are in **Russian** (team language). Change `config('services.telegram.locale')` if needed.

## Message example

```
🆕 <b>Quote request #L-152</b>
👤 Ali Valiyev · +998 90 123 45 67
🏢 OOO "Market"
📦 Zebra ZD421 ×2, Ribbon 110×300 ×10
💬 Need delivery to Samarkand
```

## Tests

`Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])])`, then trigger the action and run `Http::assertSent(...)`. Also assert that nothing is sent when the token is empty (local dev).
