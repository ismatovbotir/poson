# HTML templates

Put the source HTML/CSS/JS design templates here:

- `site/`: public website template (home, catalog, product, cart, contacts, ...)
- `admin/`: admin panel template (dashboard, tables, forms, ...)

Keep the original files untouched. Agents read them as the **design reference** and port them into
Blade layouts, components and Livewire views (see `skills/html-template-integration`).
Images, fonts and icons from a template are copied to `public/` or `resources/` during integration.
