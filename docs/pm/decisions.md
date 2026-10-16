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
