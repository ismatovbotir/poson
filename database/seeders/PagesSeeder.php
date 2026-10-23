<?php

namespace Database\Seeders;

use App\Enums\PageTemplate;
use App\Models\FaqItem;
use App\Models\Page;
use App\Rules\BrandSafeText;
use App\Support\HtmlSanitizer;
use App\Support\Site\PageDirectory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starter pages (plan 07 K15) from database/seeders/data/pages.php, keyed by
 * slug: template, needs_review, title / meta_title / meta_description / body
 * per locale and an optional FAQ list. Real content: DatabaseSeeder runs it
 * everywhere, production once at launch (`db:seed --class=PagesSeeder --force`).
 *
 * Never overwrites: a page is created only when its slug doesn't exist yet
 * (firstOrCreate by slug), and its FAQ is seeded only together with the new
 * page, so admin edits, deletions and deactivations always stay. Bodies go
 * through HtmlSanitizer, like SavePage. System slugs get their fixed
 * template and is_system; every page is active except INACTIVE (the public
 * offer waits for the lawyer, decision 2026-10-23). Texts that break the
 * brand rule are skipped and reported in $warnings.
 */
class PagesSeeder extends Seeder
{
    /**
     * Pages seeded inactive.
     *
     * @var list<string>
     */
    public const INACTIVE = ['offer'];

    /**
     * Pages always seeded with needs_review (legal drafts for the lawyer).
     *
     * @var list<string>
     */
    public const NEEDS_REVIEW = ['privacy', 'offer'];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @param  array<string, array<string, mixed>>|null  $pages  data override (tests)
     */
    public function run(?array $pages = null): void
    {
        $pages ??= require database_path('seeders/data/pages.php');
        $sort = 0;

        DB::transaction(function () use ($pages, &$sort): void {
            foreach ($pages as $slug => $data) {
                $sort++;

                if (Page::query()->where('slug', $slug)->exists()) {
                    continue;
                }

                if ($this->unsafe($data)) {
                    $this->warn("Pages: \"{$slug}\" has banned wording, skipped");

                    continue;
                }

                $page = $this->createPage((string) $slug, $data, $sort);
                $this->seedFaq($page, (array) ($data['faq'] ?? []));
            }
        });

        // DatabaseSeeder runs without model events: flush the menus, sitemap and llms.txt here.
        PageDirectory::flushAll();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPage(string $slug, array $data, int $sort): Page
    {
        $system = Page::isSystemSlug($slug);

        $page = new Page([
            'slug' => $slug,
            'template' => $system ? Page::SYSTEM[$slug] : (PageTemplate::tryFrom((string) ($data['template'] ?? '')) ?? PageTemplate::Default),
            'is_system' => $system,
            'is_active' => ! in_array($slug, self::INACTIVE, true),
            'needs_review' => (bool) ($data['needs_review'] ?? false) || in_array($slug, self::NEEDS_REVIEW, true),
            'sort' => $sort,
        ]);
        $page->content_updated_at = now();

        $page->storeTranslations('title', (array) $data['title']);
        $page->storeTranslations('body', array_map(HtmlSanitizer::cleanOrNull(...), (array) ($data['body'] ?? [])));
        $page->storeTranslations('meta_title', (array) ($data['meta_title'] ?? []));
        $page->storeTranslations('meta_description', (array) ($data['meta_description'] ?? []));
        $page->save();

        return $page;
    }

    /**
     * @param  list<array{question: array<string, string>, answer: array<string, string>}>  $items
     */
    private function seedFaq(Page $page, array $items): void
    {
        foreach (array_values($items) as $index => $data) {
            $item = new FaqItem([
                'faqable_type' => $page->getMorphClass(),
                'faqable_id' => $page->id,
                'sort' => $index + 1,
                'is_active' => true,
            ]);
            $item->storeTranslations('question', $data['question']);
            $item->storeTranslations('answer', $data['answer']);
            $item->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function unsafe(array $data): bool
    {
        $texts = [];

        foreach (['title', 'meta_title', 'meta_description', 'body'] as $field) {
            $texts = [...$texts, ...array_values((array) ($data[$field] ?? []))];
        }

        foreach ((array) ($data['faq'] ?? []) as $item) {
            $texts = [...$texts, ...array_values((array) $item['question']), ...array_values((array) $item['answer'])];
        }

        return BrandSafeText::violates(strip_tags(implode(' ', array_map('strval', $texts))));
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        $this->command?->warn($message);
    }
}
