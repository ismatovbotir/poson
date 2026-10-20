# Poson CMS

CMS and website for a company that **sells and services Auto-ID products**:
- Hardware: barcode scanners, label and receipt printers, mobile computers (TSD), RFID and POS equipment
- Consumables: labels, ribbons, receipt paper
- Software, plus device services (setup, integration, repair, support)

Customers are mostly B2B in Uzbekistan.

## Stack & decisions

- Laravel 13 · Livewire 4 · Tailwind 4 · Vite · MySQL · Pest
- **Locales:** uz (Latin, default) · ru · en. Content in JSON translatable columns, public routes under the `/{locale}` prefix.
- **Admin:** custom Livewire + Tailwind at `/admin` (no Filament).
- **Modules:** public site + catalog, and Sales/CRM (leads, customers, quotes, orders).
- **Sales flow:**
  - Cart + checkout for products with `sale_mode` cart or both
  - Quote request for equipment, projects and services
- **Payments:** Payme, Click, cash and bank transfer. Money is stored as integer **tiyin**.
- **Notifications:** Telegram (sales, service and finance chats).
- **Roles:** Admin · Content manager · Sales manager · Service engineer (spatie/laravel-permission).

## Brand positioning (all copy, all channels)

- Poson sells on **service quality**, never on low price: expert selection, setup and integration, official warranty, its own service centre, fast support, and reliable stock and delivery.
- **Never** advertise low prices or discounts in any user-facing text: site, Mini App/bot, SEO meta, notifications or banners. That rules out phrases like "cheapest", "low price", "дешевле", "цена ниже", "скидки", "акции", "arzon" and "chegirma", and also "−%" badges, crossed-out old prices and promo/sale sections.
- Prices themselves are shown plainly. The public site shows only the **Retail** price. Dealer and Wholesale prices are per-customer price levels used in admin quotes/orders and are never shown publicly or used as a selling headline.
- Internal admin wording (e.g. sort by price, price level on a quote/order) is fine.

## How to work in this repo

- The main session runs as the **`pm` agent** (`.claude/settings.json`). PM plans, delegates to the specialist agents and tracks work in `docs/pm/`.
- Before coding, load the matching skill from `.claude/skills/`. `project-conventions` always applies.
- Design templates are in `.claude/html-templates/{site,admin}`. Port them with the `html-template-integration` skill and never edit the originals.
- Every user-facing string is in uz, ru and en. Every admin action is authorized. Business logic lives in `app/Actions`.
- Ask before installing packages, running destructive DB commands or committing.
- After changes, run `vendor/bin/pint --dirty` and `php artisan test --compact`.

## Agents (`.claude/agents/`)

| Agent | Role |
|---|---|
| `pm` | Main orchestrator. Talks to the user, plans, delegates, enforces the quality gate |
| `laravel-architect` | Read-only: schema, module design, ordered task plan |
| `livewire-builder` | Implements models, actions, admin and site Livewire, Blade from templates |
| `payment-integrator` | Payme and Click webhooks, transactions, paid/refund logic |
| `test-writer` | Pest tests, runs the suite |
| `code-reviewer` | Read-only review: security, authorization, i18n, money, N+1 |
| `translation-auditor` | uz/ru/en completeness and fixes for missing keys |
| `catalog-content-writer` | Product, category and SEO texts in uz, ru and en |

## Skills (`.claude/skills/`)

| Skill | Use for |
|---|---|
| `autoid-domain` | Product types, categories, spec attributes, terminology |
| `project-conventions` | Folder layout, money, enums, actions, migrations |
| `multilingual-content` | Translatable models, localized routes, lang files |
| `catalog-module` | Categories, brands, products, specs, documents, relations |
| `admin-livewire-crud` | Admin index and form screens pattern |
| `public-site-seo` | Public pages, filters, meta, hreflang, sitemap, JSON-LD |
| `crm-leads-quotes` | Leads, customers, quotes, pipeline |
| `cart-checkout` | Cart, checkout, orders |
| `payme-integration` / `click-integration` | Payment providers |
| `telegram-notifications` | Telegram alerts |
| `roles-permissions` | Role matrix, policies, scoping |
| `testing-conventions` | Pest patterns |
| `html-template-integration` | HTML template to Blade/Livewire |
| `new-module` | End-to-end module workflow (`/new-module <Name>`) |

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
