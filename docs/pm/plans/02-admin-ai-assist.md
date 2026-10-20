# Plan 02 — Admin AI assist

Source: laravel-architect, 2026-10-20. Status: **approved 2026-10-20** (laravel/ai + app/Ai/, limits incl. monthly cap, uz "needs review"). Still open: §6 q3, q4, q6.

## 0. Prerequisites
- Not built yet: admin auth/shell, `routes/admin.php`, roles (spatie/laravel-permission not installed), rich-text editor/sanitizer.
- AI work hooks into the catalog admin shell + first form (Categories), which belong to the catalog plan.

## 1. Architecture
### 1.1 SDK
| Option | Pros | Cons |
|---|---|---|
| **`laravel/ai` v1.3** (official, Laravel 13) | One API for Anthropic + OpenAI; structured output; image/PDF attachments; OpenAI images; usage; timeouts; built-in fakes | Young 1.x (pin version); adds `config/ai.php` + `app/Ai/Agents/`; no remove.bg |
| anthropic-ai/sdk + openai-php/client | Full vendor coverage | Two SDKs, own fakes, more glue |
| Plain `Http::` | No deps | Most code to maintain |

**Recommendation:** `laravel/ai` for Claude + OpenAI, plain `Http::` for remove.bg, all behind our own interfaces.

### 1.2 Provider layer `app/Services/Ai/`
- Contracts: `TextAssistant` (generate, translate, describeImage, later extractProduct), `ImageGenerator`, `BackgroundRemover`.
- Adapters: `ClaudeTextAssistant` (agents `FieldWriter`, `Translator`, `ImageDescriber`, structured `{uz,ru,en}`), `OpenAiImageGenerator`, `RemoveBgBackgroundRemover`; bound in `AppServiceProvider`.
- DTOs carry usage + `costMicroUsd` (config rates). Failures → `AiException` codes: `timeout`, `rate_limited`, `provider_error`, `invalid_output`, `not_configured`. Raw messages never logged.
- Shared system prompt: brand rule (service quality, no price/discount talk, no invented specs), Uzbek Latin with ʻ, autoid-domain glossary, per-field output format, context in `<context>` tags treated as data.
- `BrandGuard::check()` — banned words + `−?\d+\s?%` per locale from `config/ai-assist.php`; **warns, doesn't block** (highlighted in preview, stored on log row).

### 1.3 Actions `app/Actions/Ai/`
`GenerateFieldText`, `TranslateFieldText`, `DescribeImage`, `GenerateImage`, `RemoveImageBackground`, (phase 3) `ExtractProductFromFile`.
Each: authorize `ai.use` → `AiQuota::ensureAvailable` (today's rows, Asia/Tashkent) → `ai_requests` row `pending` → `RunAiRequest` job (queue `ai`, tries=1, never retried) → `succeeded`/`failed`.
AI **never saves the entity**; the manager applies, then saves the form with normal validation.

### 1.4 Queued, not sync
Queued job + `wire:poll.1500ms` on the panel while pending/running. No streaming. Production needs `queue:work`.

## 2. Reusable admin component
- One `Admin\Ai\Assistant` Livewire slide-over (bottom sheet on mobile) in the admin layout, opened by event `ai-open`.
- Field: `<x-admin.ai-button field="product.short_description" target="form.short_description" />`; form uses `WithAiAssist` trait with `aiContext()` (built server-side from unsaved form state via `ProductAiContext`/`CategoryAiContext`) and `aiTargets()` whitelist.
- Panel: field label → mode (Generate uz/ru/en | Translate from active locale) → editable hint pre-filled from registry → Generate (spinner) → 3 preview cards with char count, amber brand warnings, Apply per card / Apply all → `ai-apply` handled only for matching component id + whitelisted target. Errors: `admin.ai.errors.{code}` + Retry.
- Images (saved images only): alt + caption uz/ru/en (vision); remove background — `kind=product` only, saved as a **new** image, original kept; generate image — only `illustration|banner|cover`, **blocked server-side for product images**; temp files pruned after 24 h.

## 3. Hint-prompt registry
- MVP in code: `App\Support\Ai\FieldRegistry` maps `fieldKey` → `FieldSpec` (type, max, format, hint template with `{category} {brand} {name} {specs}` placeholders, image flags). Templates in English. Edited hint is per request only.
- Phase 3: `ai_prompt_overrides` table + Admin edit screen with "reset to default".

## 4. Schema, permissions, config
- `ai_requests`: user_id (nullOnDelete), feature (`AiFeature`), provider (`AiProvider`), model, field_key, nullable morph subject, status (`AiRequestStatus`: pending/running/succeeded/failed/rejected), error_code, prompt, response json, brand_warnings json, input/output tokens, images, cost_micro_usd (bigint), duration_ms, timestamps. Indexes (user_id, created_at), (created_at), (provider, created_at). Prompt/response nulled after 90 days.
- Model `AiRequest` + policy + factory.
- Permissions: `ai.use` (Content manager; Admin via Gate::before); `ai-requests.viewAny` (Admin). Sales/Service: none. No CRM/PII fields in the registry.
- Config: `config/ai.php` (laravel/ai), `services.removebg`, `config/ai-assist.php` (enabled, models, timeouts, limits, cost rates, banned words, queue).
- `.env.example`: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `REMOVEBG_API_KEY`, `AI_ENABLED`, `AI_TEXT_MODEL`, `AI_VISION_MODEL`, `AI_IMAGE_MODEL`, `AI_TIMEOUT=45`, `AI_IMAGE_TIMEOUT=120`, `AI_DAILY_LIMIT_PER_USER=100`, `AI_DAILY_IMAGE_LIMIT_PER_USER=10`. Missing key → action hidden + `not_configured`.

## 5. Scope and tasks
- **MVP:** text generate + translate, brand guard, quota, request log, usage view — Categories form first, then Products text/SEO.
- **Phase 2:** image alt/caption, remove.bg, OpenAI image generation.
- **Phase 3:** fill product from photo/datasheet, prompt overrides, FAQ fields.
- Sequence: catalog admin shell → Categories form → A2–A8 → other catalog forms use `ai-button` from day one.

| # | Task | Agent |
|---|---|---|
| A1 | Approvals: `laravel/ai`, `app/Ai/` folder (spatie/laravel-permission via catalog plan) | pm |
| A2 | Install, config, env keys, enums, `ai_requests` migration/model/factory/policy, permissions in seeder | livewire-builder |
| A3 | Contracts + adapters, BrandGuard, FieldRegistry, context builders, AiQuota, actions, RunAiRequest job | livewire-builder |
| A4 | Assistant slide-over, `ai-button`, `WithAiAssist`, `admin.ai.*` lang keys; wire into Categories form | livewire-builder |
| A5 | Admin usage view (Admin only) | livewire-builder |
| A6 | Tests (quota, guard, registry, fakes, Livewire flow, whitelist, role dataset, timeout) | test-writer |
| A7 | Translation audit | translation-auditor |
| A8 | Review (secrets, logging, authz, prompt injection, whitelist) | code-reviewer |
| A9 | Roll out to Attributes/Brands/Products forms | livewire-builder |
| B1–B4 | Phase 2 image features + tests + review | builder, test-writer, reviewer |
| C1–C2 | Phase 3 extraction (never auto-creates attribute options) + prompt overrides screen | builder, test-writer |

## 6. Open questions
1. Limits 100 text / 10 image per user per day OK? Monthly USD budget cap?
2. Uzbek quality: human review of uz drafts? Generate ru first then translate to uz?
3. OpenAI image terms OK; rule "no people/third-party logos" in generated images?
4. Product data/datasheets/photos go to Anthropic/OpenAI/remove.bg (abroad); no customer data. Any NDA/data-residency sign-off?
5. Rich-text editor + HTML sanitizer — decide in catalog plan.
6. Hosting supports a queue worker (supervisor)?
7. Model ids confirmed at install; pin `laravel/ai` version.
