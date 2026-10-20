# Backlog

## Now
- [ ] Foundation (plan `docs/pm/plans/01-foundation.md`, approved 2026-10-16)
  - [x] T1–T3 tooling, locales, settings — reviewed, committed e860533 — 2026-10-20
  - [x] Review fixes on T1–T3 + T4 layout built (94 tests green) — 2026-10-20; reviewed, committed — 2026-10-20
  - [x] T6 site copy uz/ru/en — `lang/{uz,ru,en}/site.php`, 108 keys each, verified by PM — 2026-10-16
  - [x] T5 home + lead form built (139 tests green) — 2026-10-20; reviewed, fixes applied (157 tests green), committed
  - [x] T7 translation audit (price keys removed, uz validation completed) — 2026-10-20
  - [ ] T8: lang key parity test (rest of T8 covered by 157 tests) — test-writer (running)

## Next
- [ ] **Admin AI assist** (reusable field/image AI button with per-field hint prompts) — architect plan, ship together with the first admin screens (catalog); plan `docs/pm/plans/02-admin-ai-assist.md` approved 2026-10-20; MVP text → phase 2 images → phase 3 fill-from-photo
- [ ] **Catalog MVP** (plan 03 being written by architect; pre-approved 2026-10-20): admin shell + roles → categories, brands, products, specs, 3 price levels (Retail/Dealer/Wholesale) → home/catalog/product pages
- [ ] Lead form + quote request → CRM leads + Telegram

## Later
- [ ] Pre-launch: set TRUSTED_PROXIES per server; legal/marketing sign-off of privacy, offer, consent texts and hero/SEO copy (uz/ru/en); real hero stats
- [ ] Pre-launch: real company phone/contacts in settings; optional MySQL 8 test run (CI) before production
- [ ] Price import from external system (Excel/1C/API?) — ask user which system and format
- [ ] **Telegram module** (after catalog MVP + cart/checkout) — needs architect plan + package approval (bot library):
  - Bot: /start + menu button → Mini App, language uz/ru/en, order status updates to customer, quote & service request dialogs → CRM leads, "contact a manager" (customer ↔ sales chat relay)
  - Mini App: catalog, search, product, cart, checkout; Telegram login via initData; phone via shared contact; pay via same Payme/Click links
  - Admin: Telegram customers on customer card, manager replies, bot settings
  - Mini App UI design (Telegram theme-aware, in `.claude/html-templates/telegram/`)
- [ ] Cart + checkout, Payme/Click
- [ ] Missing pages: contacts, services, brands, news, static pages
- [ ] Admin screens for categories, brands, customers, quotes, payments, service, content, users/roles, settings (reuse Index/Form patterns)
- [ ] Admin dark mode (not designed)

## Done
- [x] Git initialised, first commit 0efdb01 — 2026-10-16
- [x] Laravel Boost installed (guidelines, 6 skills, MCP) — 2026-10-16
- [x] Admin design kit: 7 mobile-first pages + theme + README in `.claude/html-templates/admin` — 2026-10-16
- [x] Template decisions: Tailwind rewrite, hide login/favorites/reviews, real price tiers — 2026-10-16
- [x] Studied site template, wrote inventory — 2026-10-16
