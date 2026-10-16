# Backlog

## Now
- [ ] Foundation plan ready (`docs/pm/plans/01-foundation.md`), awaiting approval — 2026-10-16
- [ ] Foundation: locales uz/ru/en, `/{locale}` routes, settings, site layout from template (Tailwind port, mobile menu, topbar fix), home page with placeholder data — 2026-10-16

## Next
- [ ] Catalog MVP: categories, brands, products, specs, price tiers → home/catalog/product pages
- [ ] Lead form + quote request → CRM leads + Telegram

## Later
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
- [x] Laravel Boost installed (guidelines, 6 skills, MCP) — 2026-10-16
- [x] Admin design kit: 7 mobile-first pages + theme + README in `.claude/html-templates/admin` — 2026-10-16
- [x] Template decisions: Tailwind rewrite, hide login/favorites/reviews, real price tiers — 2026-10-16
- [x] Studied site template, wrote inventory — 2026-10-16
