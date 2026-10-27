# Price API (for the 1C developer)

1C sends product prices to the Poson site. The site matches each item to a product by its SKU (product code) and stores the three price levels. Only prices are synced: names, stock and other product data are not touched.

## Endpoint

```
POST https://<site>/api/v1/prices
```

- **HTTPS only.** Never call the API over plain `http://`: the token would travel unencrypted. If a token was ever sent over `http://`, treat it as leaked and rotate it (see below).
- `Content-Type: application/json` is required. Any other content type gets `415`.
- Request body: at most 256 KB.

## Authentication

Every request sends a token in the `Authorization` header:

```
Authorization: Bearer pos_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

- A site admin creates tokens in **Settings → API tokens**. The token is shown **once**. Copy it into the 1C settings straight away, because the site keeps only a hash of it.
- A token can have an expiry date (30, 90 or 180 days, 1 year, or none) and can be revoked at any time.
- A missing, wrong, expired or revoked token gets `401`. So does a token that lacks the price permission (`prices.write`), which every token created today has.
- After 10 failed authentications in a minute, the sending IP address is blocked for that minute. **While an IP is blocked, valid tokens from it are refused too** (`429 too_many_failed_attempts`). A misconfigured token in one 1C instance can therefore block another instance behind the same IP. Fix the token, then wait for `Retry-After` seconds.
- Never put the token in the URL, in logs or in emails.

## Body

```json
{
  "items": [
    { "code": "ZD421D", "retail": 7450000, "dealer": 7100000, "wholesale": 6900000 },
    { "code": "DS2208-SR", "retail": 1890000, "dealer": null, "wholesale": 0 }
  ]
}
```

| Field | Type | Rules |
|---|---|---|
| `items` | array | Required. 1 to 100 items per request. |
| `code` | string | Required. The product SKU, 1 to 64 characters. Spaces around it are removed and letters are upper-cased, so `" zd421d"` matches `ZD421D`. |
| `retail` | integer | Required. Retail price in **whole soʻm**, from 1 to 999 999 999 999 999. |
| `dealer` | integer or null | **The key is required.** Dealer price in whole soʻm (≥ 1). `null` or `0` means "no dealer price" (the site falls back to the retail price). |
| `wholesale` | integer or null | **The key is required.** Same rules as `dealer`. |

Rules:

- Prices are **whole soʻm as JSON integers**, with no tiyin, no decimals and no quotes. `1500000` is valid. `1500000.00`, `1.5e6`, `"1500000"` and `true` are not.
- Each item **replaces all three levels** of the product. To remove a dealer or wholesale price, send `null` or `0`.
- Negative numbers are invalid.
- The body must be a JSON object (`[]` is rejected with `body_not_object`), `items` must be a JSON array (`{}` is rejected with `items_not_list`), and each item must be a JSON object (an item sent as `[]` gets `item_not_object`).
- The same `code` twice in one request: **every** occurrence is rejected with `duplicate_code`. Nothing is applied for that code.
- Products in the site's trash are matched and updated too.
- Sending the same prices again is safe. The item reports `unchanged`.
- Once 1C has sent a product's prices, that product's prices and its SKU are **read-only in the site admin**. An admin can "Unlink from API" for one product, and the next request that includes its code links it again.

### Labels priced per label

Some label SKUs are sold in rolls but priced **per one label** (in the site admin the product has *Price per: label*). For these SKUs send the price of **one label** at every level, in whole soʻm, with the same rules as above. For example `{ "code": "LBL-DT-58X40-ECO", "retail": 32, "dealer": 28, "wholesale": 30 }` means 32 soʻm per label.

The site works out the roll price itself: price per label × labels per roll of the roll type the customer picks (for example 32 × 1000 = 32 000 soʻm for a roll of 1000 labels). **Do not** send roll prices for these SKUs. Ask the site admin which SKUs are priced per label.

## Response

### 200: batch processed

Every item gets its own status, in request order. Valid items are applied even when other items in the same request are invalid or unknown.

```json
{
  "sync_id": 1532,
  "received": 4,
  "updated": 1,
  "unchanged": 1,
  "unknown": 1,
  "invalid": 1,
  "items": [
    { "code": "ZD421D", "status": "updated" },
    { "code": "DS2208-SR", "status": "unchanged" },
    { "code": "NO-SUCH-SKU", "status": "unknown" },
    { "code": "TSC-TE200", "status": "invalid", "errors": ["retail_not_integer"] }
  ]
}
```

| Status | Meaning |
|---|---|
| `updated` | Prices were changed. |
| `unchanged` | The product already had these prices. |
| `unknown` | No product with this SKU on the site. Add the product in the site admin, or fix the code in 1C. |
| `invalid` | The item was not applied. See `errors`. |

`sync_id` identifies the batch in the site's log (**Catalog → Price sync**, kept for 180 days). Quote it when reporting a problem.

### Item error codes (`errors`)

| Code | Meaning |
|---|---|
| `item_not_object` | The item is not a JSON object. |
| `code_required` | `code` is missing, `null` or empty. |
| `code_not_string` | `code` is not a string (send `"12345"`, not `12345`). |
| `code_too_long` | `code` is longer than 64 characters. |
| `retail_required` | `retail` is missing or `null`. |
| `retail_not_integer` | `retail` is not a JSON integer. |
| `retail_out_of_range` | `retail` is below 1 (including `0`) or above 999 999 999 999 999. |
| `dealer_required` / `wholesale_required` | The key is missing. Send `null` for "no price". |
| `dealer_not_integer` / `wholesale_not_integer` | Not a JSON integer or `null`. |
| `dealer_out_of_range` / `wholesale_out_of_range` | Negative, or above 999 999 999 999 999. |
| `duplicate_code` | The same code appears more than once in this request. |

### Whole-request errors

These responses have the form `{"error": "<code>", "message": "<hint>"}`. The 415, 422 and 500 responses also include `sync_id`, because the rejected batch is logged. The 401, 413 and 429 responses do not.

| HTTP | `error` | Meaning |
|---|---|---|
| 401 | `unauthenticated` | Missing, wrong, expired or revoked token, or a token without the price permission. |
| 413 | `payload_too_large` | Body over 256 KB. Send fewer items per request. |
| 415 | `unsupported_media_type` | `Content-Type` is not `application/json`. |
| 422 | `invalid_json` | The body is not valid JSON. |
| 422 | `body_not_object` | The body is not a JSON object. |
| 422 | `items_missing` | No `items` key. |
| 422 | `items_not_list` | `items` is not an array. |
| 422 | `items_empty` | `items` is empty. |
| 422 | `too_many_items` | More than 100 items. Split them into several requests. |
| 429 | `rate_limited` | More than 30 requests per minute with this token. Wait for `Retry-After` seconds. |
| 429 | `too_many_failed_attempts` | 10 failed authentications per minute from this IP. The IP is blocked, valid tokens included, until `Retry-After` seconds have passed. Check the token. |
| 500 | `server_error` | Unexpected server problem. **Nothing was applied, and it is safe to retry** the same request: batches are idempotent. Retry after a short pause (for example 30 s, then 2 min, then 10 min). |
| 5xx (other) | (none, or not JSON) | Server or proxy unavailable. Also safe to retry the same request later. |

Other HTTP codes:

- `404`: wrong URL (check `/api/v1/prices`).
- `405`: wrong method (only `POST` is accepted).


Nothing is applied when the whole request is rejected. Because every batch is idempotent, re-sending a batch after any error (including a timeout where you don't know whether it arrived) is always safe.

## Limits

- 100 items per request, 256 KB per request body.
- 30 requests per minute per token.
- 10 failed authentications per minute per IP address.
- For a full price list, send it in chunks of 100 items one after another, and wait when you get a `429`.

## Example (curl)

```bash
curl -sS -X POST "https://<site>/api/v1/prices" \
  -H "Authorization: Bearer $POSON_PRICE_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data '{"items":[{"code":"ZD421D","retail":7450000,"dealer":7100000,"wholesale":null}]}'
```

## Alerts

When a request has unknown or invalid codes, or is rejected as a whole, the site posts a message to the finance Telegram chat. Each kind of problem (unknown codes, invalid items, rejected request) from the same token is reported at most once every 6 hours, whatever the exact codes. The full details are always in the log.

## Token rotation

1. A site admin creates a new token in **Settings → API tokens** and gives it to the 1C administrator.
2. The 1C administrator replaces the token in the 1C settings and checks that the next request returns `200`.
3. The site admin checks the token's "Last used" time, then **revokes** the old token. It stops working immediately.

Rotate tokens at least once a year, or straight away if a token may have leaked.
