# Site template inventory (`.claude/html-templates/site`)

Studied by PM on 2026-10-16. Originals are read-only reference.

## Files
| File | What it is | Maps to |
|---|---|---|
| `index.html` | Home: topbar, header+search, nav, hero+stats, categories (6), popular products (4), B2B banner, "why us" (4), services (3), brands strip, lead form, footer | `/{locale}` → `Site\Home` |
| `catalog.html` | Breadcrumbs, page head, filter sidebar (category, price range, brand, connection type, stock), toolbar (mobile filter toggle, chips, sort), 3-col grid of 12 cards, pager | `/{locale}/catalog/{category?}` → `Site\Catalog\Index` |
| `product.html` | Gallery (thumbs+stage), meta (rating, SKU, stock qty), buybox (price, **qty price tiers**, total, qty stepper, Add to cart, Buy in 1 click, Request quote), key specs (4), delivery/warranty/setup bullets, tabs (description, specs, delivery, reviews), related (4), mobile sticky buy bar | `/{locale}/product/{slug}` → `Site\Catalog\Show` |
| `style.css` | ~19 KB custom CSS (no Tailwind). Tokens in `:root`, BEM-ish components, breakpoints 1100/900/600 | Port to Tailwind 4 `@theme` + utilities |
| `app.js` | Vanilla JS: favorite toggle, mobile filters, tabs, gallery, qty + tier total | Alpine / Livewire |
| `logo.svg` | Logo mark (green ring + dot) | `public/assets/site/logo.svg`, favicon |

Header/footer/SVG sprite are identical on all 3 pages → one shared layout.

## Design tokens
- Colors: bg `#fff`, bg-soft `#f5f7fb`, bg-tint `#eefaf2`, ink `#0f172a` / `#475569` / `#94a3b8`, line `#e2e8f0`, brand `#1f9d55` (600 `#178045`, 50 `#e8f8ee`), ok `#12a150`, warn `#e8890c`, danger `#d93636`. Logo green `#66d080` / `#4cc46d` (differs from brand green).
- Font: Inter 400–800 (latin+cyrillic). Radius 12 / 20 px. Container 1240 px. Base 15 px.
- Buttons: primary / ghost / soft, sizes 46 / 54 px.

## Reusable Blade components
`layouts/site`, `site.topbar`, `site.header`, `site.nav`, `site.footer`, `site.icon` (sprite), `site.product-card`, `site.category-tile`, `site.section-head`, `site.breadcrumbs`, `site.stock-badge`, `site.price`, `site.tag`, `site.lead-form` (Livewire), `site.filter-group`, `site.pager` (Laravel paginator view).

## Data the template implies (check vs. catalog/CRM schema)
- Product: SKU, `price` + `compare_at_price` (sale −%), flags **hit / new / sale**, stock qty (shows "4 left", "48 pcs", "on order"), short spec line on cards, key specs (4 highlighted attributes), rating + reviews count.
- **Quantity price tiers** (from 1 / 10 / 50 pcs) — B2B wholesale pricing.
- Category: icon, product count. Brand: logo strip, brand filter.
- Spec filters by attribute (e.g. connection type) with counts.
- Settings: phone, Telegram, email, address, working hours, hero stats (1200+ items, 24 h quote, 12 mo warranty).
- Lead form: name, phone, company → CRM lead. "Request quote" and "Buy in 1 click" → leads/quick order.

## Gaps and issues
1. **Locales:** text is Russian only, switcher has RU/UZ, **no EN**. Default must be uz.
2. **Missing pages:** cart, checkout, order success/payment, contacts, services list + service page, brand page, news, static pages (delivery & payment, warranty, B2B, privacy, offer), search results, 404, favorites, login/account.
3. **Missing UI:** mobile menu drawer (burger exists, nav hidden <900 px, no drawer), catalog mega-menu, quote modal, 1-click modal, documents/drivers tab on product, quote-only product card (template always shows a cart button, even for "on order").
4. Out-of-scope features shown: customer login, favorites, reviews/rating. Need a decision.
5. Small CSS bugs: on mobile the topbar right side (Telegram, B2B, phone) doesn't hide (`.lang~*` matches nothing) → overflow; logo greens don't match brand token.
6. Product images are SVG placeholders → real images from media.

## Copy to replace when porting (brand rule: service quality, never low price)
| Template text | Replace with (idea) |
|---|---|
| B2B block "Работаете с юрлицами? Дадим цену ниже" | "Работаете с юрлицами? Персональный менеджер и сервис" — contract, invoice, payment by transfer, test samples, SLA |
| "Скидки от объёма и персональный прайс" | "Персональный менеджер и подбор под задачу" |
| Nav "Акции %" (red, `.hot`) | Remove, or replace with "Услуги / Сервис" |
| `.tag--sale` "−12%" and `<s>22 000 сум</s>` old price | Remove. Only the "Хит" and "Новинка" badges stay |
| Product "Оптовые цены для юрлиц" tiers box | Keep the table, neutral title "Цена зависит от количества", no % icon |
| Catalog sort "Дешевле / Дороже" | Keep as functional sort, labelled "По цене ↑ / ↓" |
