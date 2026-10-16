---
name: new-module
description: Step-by-step workflow to build a complete new module/entity end to end in Poson (migration, model, enum, factory, policy+permissions, action, admin Livewire Index/Form, translations, public page if needed, tests). Use when the user says "add module X", "create CRUD for X" or "/new-module X".
---

# New module workflow

Argument: the module or entity name (e.g. `Banner`, `ServiceRequest`).

1. **Clarify.** Fields, which are translatable, relations, who manages it (role), and whether it appears on the public site. Ask if unclear. Check `autoid-domain` and the existing modules.
2. **Migration + model.** Follow `project-conventions` and `multilingual-content` (JSON translatable fields, money in tiyin, enums).
3. **Factory** with useful states, and a **seeder** if reference data is needed.
4. **Permissions + policy.** Add `<resource>.*` to the seeder, map them to roles (`roles-permissions`) and write the policy.
5. **Actions** for any business operation beyond plain CRUD.
6. **Admin.** `Livewire/Admin/<Module>/Index` + `Form` following `admin-livewire-crud`, plus a route and a sidebar item with `@can`.
7. **Translations.** Labels in `lang/{uz,ru,en}/admin.php`, enum labels in `enums.php`, and the site strings.
8. **Public site** (if needed), following `public-site-seo` and, when a template exists, `html-template-integration`.
9. **Tests.** Following `testing-conventions`: CRUD happy path, validation, authorization dataset, and the public page.
10. **Finish.** Run `php artisan migrate`, `vendor/bin/pint --dirty` and `php artisan test --compact`, then report the files created and anything skipped.
