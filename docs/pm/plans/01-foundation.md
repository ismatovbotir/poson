# Plan 01 — Foundation (locales, settings, site layout, home)

Source: laravel-architect, 2026-10-16. Status: **awaiting user approval** (packages, MySQL 8, open questions).

## Key decisions proposed
- Pest 4 (+ pest-plugin-laravel); spatie/laravel-translatable ^6.14; custom `{locale}` prefix + SetLocale middleware (no mcamara); laravel-lang/lang optional.
- Dev DB MySQL 8 (`poson`), tests `poson_test` (sqlite :memory: acceptable for this increment only). Env: APP_LOCALE=uz, fallback uz.
- Settings: `config/company.php` defaults + `settings` table overrides (key unique, value json) via `App\Support\CompanySettings` (cached, invalidated on save).
- Livewire 4 layout at `resources/views/layouts/site.blade.php` (`layouts::site`); class-based components in `app/Livewire/Site/*`.
- Home placeholders via `App\Support\Site\HomeContent` returning DTOs (`app/Data/Site/*`), demo data only when `SITE_DEMO_CONTENT=true`; swap to Eloquent in catalog increment.
- Lead form: Livewire `Site\LeadForm` (validation, UzPhone, honeypot, rate limit) → `Actions\Leads\SubmitSiteLead` log stub until CRM increment.
- Support: `Money::format(tiyin)`, `Phone` normalize/display, `LocaleUrl` switcher + hreflang alternates.
- Template fixes: mobile topbar (phone + locale only), drawer menu (x-trap, Esc), remove favorites/login/"Акции", no sale tags/old price, CTA by sale_mode.

## Tasks
| # | Task | Agent | Depends |
|---|---|---|---|
| T1 | Tooling: Pest 4, translatable, livewire config, .env/.env.example/phpunit.xml, npm install, Inter via bunny | livewire-builder | approval |
| T2 | Locale foundation: SetLocale, RedirectToLocale, LocaleUrl, routes | livewire-builder | T1 |
| T3 | Settings + Money/Phone/UzPhone | livewire-builder | T1 (∥ T2) |
| T4 | Theme tokens + site layout (topbar, header, nav, drawer, footer, icons, switcher, SEO head) | livewire-builder | T2, T3 |
| T5 | Home page + lead form + DTOs/demo data | livewire-builder | T4 |
| T6 | uz/ru/en copy for site.php (brand rule) | catalog-content-writer | key list (∥ T4/T5) |
| T7 | Translation audit + validation.php uz/ru | translation-auditor | T5, T6 |
| T8 | Pest tests: routing, home ×3 locales, banned words, translation keys, lead form, units | test-writer | T2 → T7 |
| QA | code-reviewer pass, fixes, pint, full suite | code-reviewer → livewire-builder | T8 |

## Deferred
Sitemap (catalog increment), search page, real leads table + Telegram, localized 404, admin/roles, cart icon (feature flag).

## Open item for CRM plan
Lead `source` naming conflict: crm skill (`quote_form/callback/...`) vs decisions (`site/telegram/phone`) → likely `source` (channel) + `type` (form).
