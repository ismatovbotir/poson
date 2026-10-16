# Poson admin design (`.claude/html-templates/admin`)

Mobile-first admin panel prototype in the site's colors. Built with **Tailwind 4 utilities + Alpine.js** so markup
ports 1:1 into Blade/Livewire (Alpine ships with Livewire). The CDN `<script>` tags are prototype-only.

Open locally: `cd .claude/html-templates && python3 -m http.server 8765` → http://127.0.0.1:8765/admin/index.html

## Pages → Laravel

| File | Screen | Laravel target (suggested) |
|---|---|---|
| `login.html` | Staff login, UI language, error + loading states | `Admin\Auth\Login` (guest layout) |
| `index.html` | Dashboard: KPIs, revenue chart, payment split, pipeline, recent leads/orders, low stock, service queue | `Admin\Dashboard` |
| `products.html` | Index: status tabs, search, filter sheet, chips, sort, bulk bar, table (lg+) / cards (mobile), empty state, pagination | `Admin\Products\Index` |
| `product-form.html` | Edit: uz/ru/en tabs with completeness dots, slug, rich text, sale mode, **price tiers repeater**, unit, stock, **specs pre-filled from the category** (skip/restore optional ones, add extra from the library, searchable value picker with inline "new value", ★ key specs ≤ 4), images, documents, SEO + Google preview, publish/flags, related, danger zone, sticky save bar | `Admin\Products\Form` + `ProductForm` |
| `category-form.html` | Category edit: attributes inherited from parent (read-only), own attributes with order (drag on desktop, arrows on mobile), per-category **Required** and **Filter** switches, fill rate per attribute, attach from library, preview of the product form order | `Admin\Categories\Form` |
| `leads.html` | Kanban (snap-scroll on mobile) + list, My/All scope, lead slide-over (call, Telegram, activity, note, → quote / → order) | `Admin\Leads\Board` |
| `order.html` | Order detail: status stepper, line items with tier price, totals with VAT, Payme/Click transactions + refund confirm, timeline + internal note, customer (INN), delivery, owner | `Admin\Orders\Show` |
| `components.html` | Design-system reference: colors, buttons, status badges, inputs, alerts, toast/confirm, empty, skeleton | Blade components below |

Sidebar items marked `#` (categories, brands, customers, quotes, payments, service, content, users, roles, settings)
reuse the **Index** (`products.html`) and **Form** (`product-form.html`) patterns.

## Design tokens

`theme.css` holds the `@theme` block → copy into `resources/css/admin.css` (separate Vite entry).

- Brand green scale `brand-50…900` from the site (`#1f9d55` = 500, logo `#66d080` = 300).
- **Primary buttons use `brand-600` `#178045`** (4.9:1 on white, WCAG AA). `brand-500` only for accents/charts.
- Ink and neutrals = Tailwind `slate` (matches site `#0f172a / #475569 / #94a3b8 / #e2e8f0`); page bg `canvas #f5f7fb`.
- Semantic: `ok`, `warn #e8890c`, `danger #d93636`. Extra hues for statuses only: sky, violet, amber.
- Font Inter. Radius: inputs/buttons `rounded-lg`, cards `rounded-2xl`. Card = `border border-slate-200 bg-white`.
- Money shown as `1 250 000 so'm` (space thousands, `tabular-nums`); stored as tiyin.

## Layout shell (identical in every page → `components/layouts/admin.blade.php`)

- **≥ lg:** fixed dark sidebar (`w-[18rem]`, slate-900) with grouped nav, counters, user card; sticky top bar.
- **< lg:** top bar (burger, logo, search icon, language, bell, avatar) + **bottom tab bar** (Home, Catalog, Leads, Orders, Menu) + sidebar as drawer.
- Top bar: ⌘K / Ctrl+K command palette, UI language (uz/ru/en), notifications dropdown, user menu with role badge.
- Global listeners: `toast` event (→ Livewire `$this->dispatch('toast', title: …, body: …, type: 'error')`) and `confirm` event (generic confirm dialog; bottom sheet on mobile).
- Sidebar items must be wrapped in `@can` per role (see `roles-permissions` skill).

## Status colour map (use one enum → badge mapping)

| Domain | Values |
|---|---|
| Lead | new = sky · contacted = amber · quoted = violet · negotiation = slate · won = brand · lost = slate-muted |
| Payment | pending = amber · paid = brand · failed = red · refunded = slate |
| Order | new = slate · picking = sky · shipped = violet · delivered = brand · cancelled = red |
| Product | active = brand · draft = slate · archived = outline |
| Role | Admin = ink · Content = brand · Sales = sky · Service = violet |

## Blade components to extract

`x-admin.icon` (SVG sprite → `resources/svg/admin-sprite.svg`), `button`, `badge` / `status-badge`, `card`, `page-header`,
`breadcrumbs`, `input`, `money-input`, `select`, `textarea`, `toggle`, `locale-tabs`, `table` + `mobile-list`, `filter-sheet`,
`bulk-bar`, `empty-state`, `pagination` (Laravel paginator view), `dropdown`, `slide-over`, `confirm`, `toast-stack`,
`kpi-card`, `timeline`, `stepper`, `sticky-save-bar`.

## Mobile-first rules

- Write base classes for phones, add `sm: / lg: / xl:` upwards. Checked with no horizontal scroll at 360, 390 and 768 px.
- Single-column grids need `grid-cols-1` (`minmax(0,1fr)`) so long text truncates instead of widening the page.
- Touch targets ≥ 44 px on mobile (`h-11`), 40 px on desktop (`lg:h-10`). Inputs `text-base` on phones to stop iOS zoom where needed.
- Tables only from `lg`; below that the same data renders as cards. Secondary columns hide progressively (`xl:` / `2xl:`).
- Modals become bottom sheets on mobile; slide-overs go full-screen. Respect `env(safe-area-inset-bottom)`.
- Fixed bars on mobile sit above the bottom tab bar (`bottom-[calc(3.75rem+env(safe-area-inset-bottom))]`).

## Brand rule

No sale pricing anywhere: no old/crossed-out price, no −% badges, no "Aksiya" (sale) flag. See "Brand positioning" in the root `CLAUDE.md`.

## Notes for implementation

- Sample data (names, numbers) is fake — everything comes from models/settings and `__()` keys in uz/ru/en.
- Prototype JS data (`productsIndex()`, `leadsBoard()`, `productForm()`) becomes Livewire state; keep Alpine only for pure UI (dropdowns, tabs, sheets).
- The section nav on the product form doesn't yet follow scrolling — add `x-intersect` (Alpine Intersect plugin) or drop it.
- Kanban drag-and-drop is not in the prototype; use the stage select in the slide-over (MVP), add sortable later.
- Dark mode is not designed yet.
