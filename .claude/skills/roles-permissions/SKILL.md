---
name: roles-permissions
description: Roles and authorization for the admin panel using spatie/laravel-permission. Covers the Admin, Content manager, Sales manager and Service engineer roles, the permission naming scheme, policies, the seeder, sidebar @can, and record-level scoping (sales managers see own leads). Use when adding a model, admin screen or any access rule.
---

# Roles & permissions

Package: `spatie/laravel-permission`. Permissions are named `<resource>.<ability>`, e.g. `products.update`.
Abilities: `viewAny`, `view`, `create`, `update`, `delete`, plus custom ones (`leads.assign`, `orders.mark-paid`, `quotes.send`).

## Role matrix

| Resource | admin | content_manager | sales_manager | service_engineer |
|---|---|---|---|---|
| Categories, brands, attributes | all | all | view | view |
| Products (hardware, consumable) | all | all | view | view |
| Products (software, service) | all | all | view | all |
| Pages, news, banners, SEO | all | all | – | – |
| Leads (sales sources) | all | – | own + unassigned | – |
| Leads (service_request) | all | – | view | own + unassigned |
| Customers, contacts | all | – | all (edit own) | view |
| Quotes | all | – | own | own (service quotes) |
| Orders | all | – | all | view |
| Payments | all | – | view | – |
| Users, roles, settings, integrations | all | – | – | – |

- `admin` uses `Gate::before(fn ($user) => $user->hasRole('admin') ? true : null)`.
- Record-level rules ("own") live in **policies** and in a matching query scope (`Lead::visibleTo($user)`). Index screens must use the scope, so lists and direct URLs agree.

## Implementation

- `RolesAndPermissionsSeeder` is idempotent (`firstOrCreate`). Enum `App\Enums\Role` holds the role names.
- Each model has a policy that checks `$user->can('<resource>.<ability>')` plus ownership.
- Middleware `admin` lets in a user with any of the four roles.
- The admin sidebar and buttons are wrapped in `@can`.
- Tests: for each new screen, test that a user without permission gets 403 and that each allowed role gets 200 (use Pest datasets of roles).
