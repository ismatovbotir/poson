---
name: html-template-integration
description: How to convert the HTML design templates in .claude/html-templates/{site,admin} into Laravel Blade layouts, Blade components and Livewire 4 views styled with Tailwind 4. Use when the user asks to "use the template", "make it look like the design", or when building any page that has a matching template file.
---

# HTML template → Blade / Livewire

Sources: `.claude/html-templates/site/` (public) and `.claude/html-templates/admin/` (admin).
**Never edit the originals.** They are the reference.

## Process

1. **Inventory.** List the template files, map each one to a route or component, and note the shared parts (header, footer, sidebar, cards, forms, modals). Show the mapping to the user before converting a whole template.
2. **Assets.**
   - If the template uses Tailwind: port its classes directly and move custom theme values (colors, fonts) into `@theme` in `resources/css/app.css`.
   - If it uses Bootstrap or custom CSS: ask the user whether to **rewrite in Tailwind** (preferred) or keep the template CSS as a separate Vite entry (`resources/css/site.css`).
   - Images and fonts go to `public/assets/{site,admin}/...` or `resources/images`, referenced with `Vite::asset()` or `asset()`.
   - Template JS (sliders, dropdowns): prefer Alpine.js, which ships with Livewire. Only keep a vendor lib (Swiper, etc.) through npm and Vite when Alpine isn't enough.
3. **Layouts.**
   - `components/layouts/site.blade.php` from the site template shell
   - `components/layouts/admin.blade.php` from the admin shell
   - Both use `@vite`, `@livewireStyles`/`@livewireScripts` (if not auto-injected), a `{{ $title }}` slot and SEO stacks.
4. **Components.** Repeated markup becomes anonymous Blade components (`x-site.product-card`, `x-site.breadcrumbs`, `x-admin.table`, ...). Expose props for the data and keep the template's classes.
5. **Pages.** Static content in the template becomes real data:
   - Product names and prices from models
   - Text from `__()` keys in uz, ru and en (see `multilingual-content`)
   - Links from `route()` with the locale
   - Forms become Livewire components with `wire:model` and `wire:submit`
6. **Verify.** Run `npm run build`, open the page, compare it with the template at desktop and mobile widths, and check that the language switcher, hreflang and forms work.

## Rules

- No hard-coded Lorem ipsum, demo prices or phone numbers. Use settings (`settings` table or `config/company.php`).
- Keep semantic HTML and accessibility: alt text, labels, focus states.
- If the template is missing a page (for example the quote modal or a checkout step), build it in the same visual language and tell the user.
