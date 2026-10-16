---
name: catalog-module
description: Data model and rules for the product catalog. Covers categories (tree), brands, products of type hardware/consumable/software/service, spec attributes per category, images, downloadable documents (datasheets, drivers), accessories/compatible consumables, and cart-vs-quote availability. Use when building or changing anything catalog-related.
---

# Catalog module

Read `autoid-domain` first for terminology and the product types.

## Tables

- `categories`: id, parent_id (nullable, self FK), name (json), description (json), slug, image, sort, is_active, seo (json)
- `brands`: id, name (string), slug, logo, website, is_active, sort
- `products`
  - Core: id, category_id, brand_id (nullable), type (enum ProductType), sku (unique), model, name (json), short_description (json), description (json), slug
  - Pricing: price (tiyin, nullable = price on request), unit (enum: piece/roll/box/pack/license/hour)
  - Flags and status: min_qty (default 1), sale_mode (enum: `cart`, `quote`, `both`), stock_status (enum: in_stock/on_order/out_of_stock), is_active, is_featured
  - Other: warranty_months (nullable), seo (json), sort, timestamps, softDeletes
- `attributes`: id, code (unique), name (json), unit (nullable, e.g. "mm", "dpi"), type (enum: text/number/boolean/select/multiselect), is_filterable (default for new category assignments), sort. This is the global **attribute library**
- `attribute_options`: id, attribute_id, value (json), sort. Values for `select` and `multiselect` attributes
- `category_attribute` pivot: category_id, attribute_id, is_required, is_filterable, sort. Says which attributes a category's products get by default and which are public filters for that category
- `product_attribute_values`: product_id, attribute_id, attribute_option_id (nullable), value_text (json, nullable), value_number (decimal, nullable), value_bool (nullable), is_key (bool, shown on the product card, max 4 per product), sort. A `multiselect` value is one row per chosen option. Any attribute from the library is allowed, not only the category's
- `product_attribute_skips`: product_id, attribute_id (unique pair). Category attributes the product deliberately does not use
- `product_images`: product_id, path, alt (json), sort. Or spatie medialibrary collection `images`
- `product_documents`: product_id, title (json), type (enum: datasheet/manual/driver/firmware/certificate), path or url, locale (nullable)
- `product_relations`: product_id, related_id, type (enum: accessory/consumable/alternative/software). Links a printer to its labels and ribbons, a scanner to its stand, and so on

Software and services use the same `products` table with `type` set. Type-specific fields go in attributes, such as license_term and seats for software, or duration and sla_hours for services.

## Rules

- **No sale pricing** (decided 2026-10-16): no `old_price`, no crossed-out prices, no −% or "sale" badges, no promo category. Badges allowed: "Hit" and "New". See "Brand positioning" in `CLAUDE.md`.
- **Attribute inheritance** (decided 2026-10-16):
  - A product's attributes = its category's attributes (including ancestor categories, nearest first) − skipped + extra.
  - On create, and when the category changes, the form pre-fills the category's attributes in pivot `sort` order.
  - **Skip:** an optional category attribute can be skipped for one product (row in `product_attribute_skips`). Skipped attributes don't count as missing and are hidden on the site. Required attributes can't be skipped.
  - **Extra:** any attribute from the library can be added to a single product. Extras show in the product page specs but are not category filters.
  - **Values:** `select`/`multiselect` values are picked from `attribute_options` with search. Users with `attributes.update` can create a new option inline (through an action), which is then reusable everywhere.
  - **Category change:** existing values are kept. Those not in the new category become extras, and the form shows a notice.
  - **Required:** active products must have a value for every required category attribute. Drafts may be incomplete. Adding a required attribute to a category with products doesn't unpublish them; they show as "specs incomplete" in the admin products filter.
- `sale_mode` decides the UI. `cart` shows Add to cart, `quote` shows Request quote, `both` shows both buttons. A `null` price forces `quote`.
- Consumables must respect `min_qty` and multiples in the cart.
- Category filters on the public site are built from `attributes.is_filterable` for that category, including its ancestors' attributes.
- Deleting a category that has products is blocked by the policy/action. Ask the user to move the products first.
- Scope `Product::query()->active()` only returns active products in active categories.
- Eager-load `brand`, `category` and the first image on listing pages. Avoid N+1, which tests can check with `Model::preventLazyLoading()`.

## Admin screens

- Categories: tree list with drag sort, plus a form with attribute assignment
- Brands: table and form
- Attributes: table and form with options
- Products: table filtered by category, brand, type, active and stock. The form has tabs: Main (locale tabs) · Price & sale · Specs (dynamic per category) · Images · Documents · Relations · SEO

See `references/spec-attributes.md` for seed data.
