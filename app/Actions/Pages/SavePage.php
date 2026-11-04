<?php

namespace App\Actions\Pages;

use App\Enums\AuditAction;
use App\Enums\PageTemplate;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Page;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Support\HtmlSanitizer;
use App\Support\Site\StructuredData;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a static page (fields only; the cover image goes through
 * UpdateCatalogImage, the FAQ through App\Actions\Faq\*).
 *
 * Re-checks what must never be stored whatever the caller: the uz title, the
 * brand rule (BrandSafeText) on every public text, sanitized body HTML, a
 * unique Latin slug generated once from the title (never changed after that,
 * incoming slugs are ignored) that no custom page may take from the system
 * list, and the fixed slug and template of system pages (only texts, SEO,
 * sort, active and the review flag change there). A generated slug taken
 * concurrently is regenerated once (Slug::saveGenerated).
 *
 * Legal text (privacy, offer): a non-Admin change of the title or body on an
 * approved page raises needs_review again (audited, no content); an Admin's
 * own text edit of an approved page keeps the flag off and is audited too.
 * Clearing needs_review on the privacy or offer page is Admin only (T21-24):
 * for anyone else (or no actor) the flag is kept as it was and the rest of
 * the save goes through; an Admin's clearing writes a SettingsAudit row
 * (who, when, IP; never page content).
 *
 * content_updated_at is set on create and whenever the title or body
 * actually change (not on SEO, sort, active or review-flag edits).
 */
class SavePage
{
    public const LEGAL_REVIEW_SLUGS = ['privacy', 'offer'];

    /**
     * @param  array{
     *     title: array<string, string|null>,
     *     body: array<string, string|null>,
     *     meta_title: array<string, string|null>,
     *     meta_description: array<string, string|null>,
     *     template: string|null,
     *     sort: int|null,
     *     is_active: bool,
     *     needs_review: bool
     * }  $data
     *
     * @throws ValidationException
     */
    public function handle(?Page $page, array $data, ?User $actor = null, ?string $ip = null): Page
    {
        $page ??= new Page;
        $body = array_map(HtmlSanitizer::cleanOrNull(...), $data['body'] ?? []);

        $this->check($data, $body);

        return DB::transaction(function () use ($page, $data, $body, $actor, $ip): Page {
            $isAdmin = (bool) $actor?->hasRole(Role::Admin->value);
            $reraised = false;
            $approvedBefore = $page->exists && ! $page->needs_review;
            $clearsLegalReview = $this->clearsLegalReview($page, (bool) $data['needs_review']);

            if ($clearsLegalReview && ! $isAdmin) {
                $data['needs_review'] = true;
                $clearsLegalReview = false;
            }

            $contentBefore = $this->content($page);

            $page->storeTranslations('title', $data['title']);
            $page->storeTranslations('body', $body);

            if (! $page->exists || $this->content($page) !== $contentBefore) {
                $page->content_updated_at = now();
            }

            $legalContentChanged = $page->exists && $this->isLegalReviewPage($page) && $this->content($page) !== $contentBefore;

            if ($legalContentChanged && ! $isAdmin) {
                $reraised = ! $page->needs_review;
                $data['needs_review'] = true;
            }

            $page->storeTranslations('meta_title', $data['meta_title'] ?? []);
            $page->storeTranslations('meta_description', $data['meta_description'] ?? []);

            $generated = ! $page->is_system && ! ($page->exists && (string) $page->getOriginal('slug') !== '');

            $page->fill([
                'slug' => $page->is_system ? $page->slug : $this->slug($page, $data),
                'template' => $page->is_system ? $page->template : $this->template($data['template'] ?? null),
                'sort' => $data['sort'] ?? ($page->exists ? $page->sort : (int) Page::query()->max('sort') + 1),
                'is_active' => (bool) $data['is_active'],
                'needs_review' => (bool) $data['needs_review'],
            ]);

            $page->is_system = (bool) $page->is_system;

            // A page created concurrently with the same title: next free slug, once.
            Slug::saveGenerated($page, $generated, fn (string $clashed): string => $this->slug($page, $data, $clashed));

            if ($clearsLegalReview) {
                SettingsAudit::record(SettingsArea::Legal, 'pages.'.$page->slug.'.needs_review', AuditAction::Cleared, $actor, $ip);
            }

            if ($reraised) {
                SettingsAudit::record(SettingsArea::Legal, 'pages.'.$page->slug.'.review_reraised', AuditAction::Updated, $actor, $ip);
            } elseif ($legalContentChanged && $isAdmin && $approvedBefore) {
                SettingsAudit::record(SettingsArea::Legal, 'pages.'.$page->slug.'.approved_text_changed', AuditAction::Updated, $actor, $ip);
            }

            return $page;
        });
    }

    public static function isReviewLockedFor(?User $user, ?Page $page): bool
    {
        return $page !== null && in_array($page->slug, self::LEGAL_REVIEW_SLUGS, true) && ! $user?->hasRole(Role::Admin->value);
    }

    private function isLegalReviewPage(Page $page): bool
    {
        return in_array($page->slug, self::LEGAL_REVIEW_SLUGS, true);
    }

    /**
     * Whether this save turns needs_review off on the privacy or offer page.
     */
    private function clearsLegalReview(Page $page, bool $needsReview): bool
    {
        return $page->exists
            && ! $needsReview
            && $page->needs_review
            && in_array($page->slug, self::LEGAL_REVIEW_SLUGS, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $body
     *
     * @throws ValidationException
     */
    private function check(array $data, array $body): void
    {
        $errors = [];

        if (trim((string) ($data['title']['uz'] ?? '')) === '') {
            $errors['title.uz'] = __('validation.required', ['attribute' => __('admin.pages.fields.title').' (UZ)']);
        }

        $texts = [
            'title' => $data['title'] ?? [],
            'meta_title' => $data['meta_title'] ?? [],
            'meta_description' => $data['meta_description'] ?? [],
            'body' => array_map(fn (?string $html): string => StructuredData::plainText((string) $html), $body),
        ];

        foreach ($texts as $field => $values) {
            foreach (config('app.supported_locales') as $locale) {
                $text = (string) ($values[$locale] ?? '');

                if (! isset($errors["{$field}.{$locale}"]) && $text !== '' && BrandSafeText::violates($text)) {
                    $errors["{$field}.{$locale}"] = __('validation.brand_safe', ['attribute' => __("admin.pages.fields.{$field}").' ('.strtoupper($locale).')']);
                }
            }
        }

        foreach ($body as $locale => $html) {
            if (mb_strlen((string) $html) > Page::BODY_MAX) {
                $errors["body.{$locale}"] = __('validation.max.string', ['attribute' => __('admin.pages.fields.body').' ('.strtoupper((string) $locale).')', 'max' => Page::BODY_MAX]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The stored slug once there is one (never changed, whatever the input);
     * otherwise a unique slug from the en title, else uz, that is not on the
     * system list (nor $except, a slug that just clashed). Incoming slugs are
     * ignored.
     *
     * @param  array<string, mixed>  $data
     */
    private function slug(Page $page, array $data, ?string $except = null): string
    {
        $stored = (string) $page->getOriginal('slug');

        if ($page->exists && $stored !== '') {
            return $stored;
        }

        $ignoreId = $page->exists ? $page->id : null;
        $source = filled($data['title']['en'] ?? null) ? $data['title']['en'] : ($data['title']['uz'] ?? '');

        return Slug::unique(
            Slug::make($source) ?: 'page',
            fn (string $slug): bool => $slug === $except || Page::isSystemSlug($slug) || Slug::taken(Page::class, $slug, $ignoreId),
        );
    }

    /**
     * Title and body as stored (or about to be), locale order normalized:
     * MySQL reorders JSON keys, so raw strings can't be compared.
     *
     * @return array{title: array<string, mixed>|null, body: array<string, mixed>|null}
     */
    private function content(Page $page): array
    {
        $content = [];

        foreach (['title', 'body'] as $key) {
            $raw = $page->getAttributes()[$key] ?? null;
            $value = is_string($raw) ? json_decode($raw, true) : $raw;

            if (is_array($value)) {
                ksort($value);
            }

            $content[$key] = is_array($value) && $value !== [] ? $value : null;
        }

        return $content;
    }

    private function template(?string $value): PageTemplate
    {
        return PageTemplate::tryFrom((string) $value) ?? PageTemplate::Default;
    }
}
