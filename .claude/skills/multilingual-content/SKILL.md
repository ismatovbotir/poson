---
name: multilingual-content
description: How to handle uz/ru/en translations in Poson. Covers translatable model attributes (JSON columns), localized routes with locale prefix, slugs, lang files, admin locale tabs and fallbacks. Use when creating models with user-facing text, routes, Blade views or admin forms.
---

# Multilingual content (uz · ru · en)

- Supported locales: `config('app.supported_locales') = ['uz', 'ru', 'en']`. Default and fallback: `uz`.
- Uzbek is written in **Latin** script.

## Database content

- Use `Spatie\Translatable\HasTranslations`, with `public array $translatable = ['name', 'description', ...]`.
- Translatable columns are `json`. Required in **uz**; ru and en are optional and fall back to uz.
- **Slug**: one shared Latin slug per record (`slug` string, unique). Don't translate slugs unless the user asks. Generate it from the uz name, or from the en name if present.
- Searching: `where('name->ru', 'like', "%{$q}%")` for the current locale. Add a MySQL generated column + index only when performance requires it.

## Routes

- Public routes are grouped under the `/{locale}` prefix, with a `SetLocale` middleware that validates against the supported locales.
- `/` redirects to `/{preferred-or-default}`.
- Use the `route()` helper with the current locale. A `localized_url($locale)` helper builds the language switcher and the hreflang links.
- The admin panel is at `/admin`. Its UI language is the user's `locale` column (default `ru`). Content editing shows **all three locales** as tabs.

## UI strings

- `lang/{uz,ru,en}/*.php` with grouped keys (`site.php`, `admin.php`, `enums.php`, `validation.php`). Use `__('site.cart.add')`.
- Never put a raw user-facing string in Blade or PHP. Add the key to **all three** files in the same change.

## Admin form pattern

```php
// Livewire Form object
public array $name = ['uz' => '', 'ru' => '', 'en' => ''];

protected function rules(): array
{
    return [
        'name.uz' => ['required', 'string', 'max:255'],
        'name.ru' => ['nullable', 'string', 'max:255'],
        'name.en' => ['nullable', 'string', 'max:255'],
    ];
}
```

Use the `<x-admin.locale-tabs>` component, which wraps inputs per locale and shows a red dot on a tab with errors.
