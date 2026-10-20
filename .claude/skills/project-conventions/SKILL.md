---
name: project-conventions
description: Coding conventions for the Poson Laravel 13 + Livewire 4 + Tailwind 4 + MySQL project. Covers folder layout, naming, money handling, enums, actions, validation and migrations. Use before writing or modifying any PHP, Blade or migration in this repo.
---

# Project conventions

## Layout

```
app/
  Actions/<Module>/          # single-purpose business operations (CreateQuoteRequest, PlaceOrder)
  Enums/                     # backed enums with label() via __()
  Http/Controllers/Webhooks/ # Payme, Click, Telegram (no Livewire there)
  Livewire/Admin/<Module>/   # admin screens: Index, Form (create+edit), Show
  Livewire/Site/<Module>/    # public components
  Models/
  Notifications/
  Policies/
  Services/Payments/{Payme,Click}/
  Support/                   # Money, LocaleUrl helpers
resources/views/
  components/admin/          # x-admin.table, x-admin.input, ...
  components/site/
  livewire/admin/<module>/
  livewire/site/<module>/
lang/{uz,ru,en}/
```

## Rules

- **Money**: store integers in **tiyin** (UZS × 100) in `unsignedBigInteger` columns named `*_amount` or `price`. Use `App\Support\Money` for formatting. Never use floats.
- **Enums**: PHP backed string enums for statuses and types. Cast them on the model. Each enum has `label(): string` returning `__("enums.<enum>.<case>")`.
- **Business logic** lives in `Actions` (invokable or `handle()`), not in Livewire components or controllers. Components validate, call an action and render.
- **Validation**: Livewire Form objects (`Livewire\Form`) for admin forms. Webhooks validate manually and return their protocol's error format.
- **Authorization**: every admin component calls `$this->authorize()` in `mount()` and before writes. Policies are required for every model (see `roles-permissions`).
- **Models**: `$fillable` is explicit. Use `casts()` method syntax. Add `HasFactory` and a factory for every model.
- **Migrations**: one table per migration. Use foreign keys with `constrained()->cascadeOnDelete()` or `nullOnDelete()`, chosen deliberately. Translatable fields are `json`.
- **Queues**: notifications and external HTTP calls (Telegram) are queued.
- **Config/secrets**: in `config/services.php` from `.env`, never hard-coded.
- **Formatting**: run `vendor/bin/pint --dirty` after changes.
- **Tests**: Pest feature test for each action, admin screen and webhook (see `testing-conventions`).

## Credentials

- **All credentials and secrets live only in `.env`**: Telegram bot token, chat IDs and webhook secret; Payme and Click keys and IDs; mail, SMS and API keys; any import/1C credentials.
- Read them only via `config/*.php` (`env()` is called only inside config files), e.g. `config('services.telegram.bot_token')`.
- Never store them in the DB `settings` table, show or edit them in admin, commit them, log them or put them in test fixtures (use fake values in `phpunit.xml`/tests).
- Every new key goes into `.env.example` with an empty or obviously fake value and a short comment.
- Admin may show only a read-only "configured / not configured" status.

## Packages (expected)

- `spatie/laravel-translatable` for JSON translatable attributes
- `spatie/laravel-permission` for roles and permissions
- `spatie/laravel-medialibrary` for product images and documents (optional, otherwise use `Storage` + a `media` table)
- `mcamara/laravel-localization` **or** a custom `{locale}` route prefix middleware. Pick one, then document it in `multilingual-content`
- `pestphp/pest` + `pestphp/pest-plugin-laravel`

Check `composer.json` before assuming a package is installed. Ask the user before adding a new package.
