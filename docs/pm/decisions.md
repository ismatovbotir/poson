# Decisions

| Date | Decision | Reason |
|---|---|---|
| 2026-10-16 | Site template = 3 pages (home, catalog, product) + shared layout; treat it as the visual language for the missing pages | Only these pages are in the template |
| 2026-10-16 | Rewrite template CSS in Tailwind 4 (tokens → `@theme`), no separate `site.css` | One styling system, matches the stack |
| 2026-10-16 | Hide customer login, favorites and reviews/rating for MVP | Not in project scope; add later |
| 2026-10-16 | Quantity price tiers are real per-product data (min qty → unit price, tiyin), used in cart | B2B wholesale pricing in the template |
| 2026-10-16 | First increment: Boost + uz/ru/en locale routing + settings + site layout (topbar, header, mobile menu, footer) + home page with placeholder data | Small, shippable foundation |
| 2026-10-16 | Admin design made in-house: Tailwind 4 + Alpine prototype in `.claude/html-templates/admin`, site colors, mobile-first (bottom tab bar + drawer < lg, dark sidebar ≥ lg) | No admin template was provided; Tailwind/Alpine ports 1:1 to Livewire |
| 2026-10-16 | Admin primary buttons use brand-600 `#178045`, not site brand-500 | brand-500 on white fails WCAG AA for text (≈3.3:1) |
| 2026-10-16 | Admin UI default language uz; uz/ru/en switch in top bar | Matches site locales |
| 2026-10-16 | Add Telegram bot + Mini App for catalog and ordering, built after the site catalog MVP | Reuse one catalog/cart/order core instead of duplicating |
| 2026-10-16 | Bot scope: open Mini App, order status updates, quote & service request dialogs → leads, contact-a-manager relay | User choice |
| 2026-10-16 | Mini App pays with the same Payme/Click flow as the site (no Telegram native payments) | One payment integration to build and maintain |
| 2026-10-16 | Mini App customer = Telegram account (verified initData) + phone via shared contact, matched to CRM customers | Low friction, no passwords; phone links to existing B2B customers |
| 2026-10-16 | Constraints for earlier work: business logic only in `app/Actions` (no logic in site Livewire); cart owner can be session or customer; `customers` has nullable `telegram_id`; orders/leads have `source` (site/telegram/phone/…); order status changes fire events | So the Telegram module plugs in without refactoring |
| 2026-10-16 | Admin Catalog menu order: Categories → Attributes → Brands → Products | Setup entities first, then items (user request) |
| 2026-10-16 | Product attributes: category (+ ancestors) attributes pre-fill the product; optional ones can be skipped per product; extra attributes from the library can be added; select values picked with search, new values can be added inline; per-category is_required/is_filterable on the pivot; new `multiselect` type | User request; catalog-module skill updated |
| 2026-10-16 | **Brand rule: sell on service quality, never on low price.** No discount/sale/cheap wording, −% badges, old prices or promo sections in any channel; `old_price` dropped from the catalog | User direction; written into CLAUDE.md, content-writer agent and catalog-module skill |
| 2026-10-16 | Packages approved: Pest 4 (+ pest-plugin-laravel), spatie/laravel-translatable, laravel-lang/lang (dev) | Foundation plan 01 |
| 2026-10-16 | SQLite for dev in the foundation increment; switch to MySQL 8 before the catalog increment | User choice; local MySQL is 5.7 (too old) |
| 2026-10-16 | Uzbek spelling: official ʻ (U+02BB) and gʻ/oʻ in displayed text; slugs/URLs and search use and accept plain ' or none | One convention for copy, URLs and search |
| 2026-10-16 | Git initialised; first commit of the Laravel skeleton + PM/agent setup | Reviewable, reversible increments |
| 2026-10-20 | SQLite stays the dev DB (supersedes the "switch to MySQL 8 before catalog" item); production remains MySQL 8, and migrations must stay MySQL-compatible | User choice |
| 2026-10-20 | **Pricing = 3 price levels per product (Retail / Dealer / Wholesale), assigned per customer — not quantity tiers.** Supersedes the 2026-10-16 "quantity price tiers" decision. Columns `price`, `price_dealer`, `price_wholesale` (tiyin); `customers.price_level` | User: the CRM has 3 prices tied to the customer, not to qty |
| 2026-10-20 | Public site, public cart and Mini App show/use only the Retail price; Dealer/Wholesale apply only in admin quotes/orders for the assigned customer | User choice; no customer login in MVP |
| 2026-10-20 | Prices are entered in admin **and** imported from an external system (source/format TBD) | User answer "both" |
| 2026-10-20 | All credentials and secrets (Telegram, Payme, Click, mail/SMS, import) live only in `.env`, read through `config/`; never in the DB/admin, git or logs; `.env.example` lists keys with empty values. Admin shows only a configured/not-configured status | User direction |
| 2026-10-20 | `config('app.default_locale')` (env APP_LOCALE, default uz) is the single source for the default/x-default locale; `CompanySettings` bound as `scoped` (memo reset per request/job) | T4 agent finding; runtime `setLocale` overwrites `app.locale` |
| 2026-10-20 | Retired lang keys `price.from_qty` / `price.depends_on_qty` are removed in T7 (translation audit) | Pricing is price levels, not qty tiers |
| 2026-10-20 | **Admin AI assist**: AI button on every admin content field and image; per-field hint prompt (editable before sending); provider Claude (Anthropic) for text and vision; text fields generate uz/ru/en at once with preview → Apply; roles Admin + Content manager, daily per-user limit + request/cost log; key in .env | User choice |
| 2026-10-20 | AI image features wanted: alt text + caption uz/ru/en, generate image from prompt (non-product images only), background cleanup for product photos, fill product fields from photo/datasheet | User choice; image generation and background removal need a non-Claude provider (pending user decision) |
| 2026-10-20 | AI image providers: OpenAI (image generation for banners/covers/illustrations, never product photos) + remove.bg (product photo background cleanup). Keys `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `REMOVEBG_API_KEY` in .env | User choice |
| 2026-10-20 | Livewire default `component_layout` = `layouts::site`; every admin Livewire component declares its layout explicitly with `#[Layout]` | Code review T4 |
| 2026-10-20 | Plan 02 (admin AI assist) approved: package `laravel/ai` (version pinned) + `app/Ai/` folder; remove.bg via plain `Http::` | User approval |
| 2026-10-20 | AI limits: 100 text + 10 image requests per user per day, plus monthly USD budget cap in .env (`AI_MONTHLY_BUDGET_USD`) that disables AI when reached | User choice |
| 2026-10-20 | AI writes uz/ru/en directly in one call; the uz card is marked "check before publishing" | User choice |
| 2026-10-20 | Leads have two enums: `LeadSource` = channel (site, telegram, phone, manual) and `LeadType` = form/intent (callback, quote, service_request, checkout). Resolves the plan-01 open item | crm skill used one mixed `source`; T5 review |
| 2026-10-20 | Rate limiting uses the real client IP via `trustProxies(at: env('TRUSTED_PROXIES'))` with explicit proxy IPs/CIDRs, never `*` unrestricted; set per environment | T5 review: behind a proxy all visitors would share one bucket |
| 2026-10-20 | User pre-approved Plan 03 (Catalog MVP) incl. its schema and packages, and the foundation commit ("all approved, start"). PM vets packages (first-party/widely used, pinned) and reports them; anything unusual still goes back to the user | User direction |
