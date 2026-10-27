<?php

namespace Database\Seeders;

use App\Enums\NewsKind;
use App\Enums\NewsStatus;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Setting;
use App\Rules\BrandSafeText;
use App\Support\HtmlSanitizer;
use App\Support\Site\NewsDirectory;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starter knowledge articles (decision 2026-10-24) from
 * database/seeders/data/articles.php: a list of
 * ['slug', 'confirm' => bool (optional), 'categories' => [category slugs],
 * 'title' / 'excerpt' / 'body' / 'meta_title' / 'meta_description' => {uz, ru, en}].
 * Real content: DatabaseSeeder runs it everywhere, right after
 * CatalogSeeder; production runs it once at the first launch, after the
 * categories exist (`db:seed --class=ArticlesSeeder --force`).
 *
 * Every article is created as a **draft** (kind article, no publication
 * date) for the content team to review and publish in admin.
 *
 * Never overwrites, never resurrects: the seeded slugs are remembered in the
 * settings row SEEDED_KEY, and a remembered slug is never created again, even
 * after an admin deleted the article. On a re-run, a seeded article that is
 * still a draft without any category gets its categories linked (the
 * catalog may have been seeded later). A slug taken by a news post (the
 * slug space is shared) is skipped with a warning.
 *
 * Checks like SaveNewsPost and the admin form: bodies go through
 * HtmlSanitizer; an Uzbek title is required; texts breaking the brand rule
 * (body included) or longer than the form limits are skipped. Unknown
 * category slugs are skipped; with no categories at all the seeder stops
 * (it never creates unlinked articles). Articles marked 'confirm' => true
 * hold facts still to be confirmed: news_posts has no review flag, so they
 * are only reported (never in any public text). Every skip and note lands
 * in $warnings and the console.
 *
 * A missing data file (the content is still being written) is a no-op with a warning.
 */
class ArticlesSeeder extends Seeder
{
    public const DATA_PATH = 'seeders/data/articles.php';

    /**
     * Settings row (group "system") listing every slug this seeder created.
     */
    public const SEEDED_KEY = 'seeders.articles';

    /**
     * Longest text per locale, as in NewsPostForm (body: NewsPost::BODY_MAX).
     *
     * @var array<string, int>
     */
    public const MAX_LENGTHS = [
        'title' => 255,
        'excerpt' => 500,
        'meta_title' => 255,
        'meta_description' => 500,
    ];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @param  string|null  $path  data file override (tests)
     */
    public function run(?string $path = null): void
    {
        $path ??= database_path(self::DATA_PATH);

        if (! is_file($path)) {
            $this->warn('Articles: '.basename($path).' not found, nothing seeded');

            return;
        }

        $articles = require $path;

        if (! is_array($articles)) {
            $this->warn('Articles: '.basename($path).' does not return a list, nothing seeded');

            return;
        }

        $categories = Category::query()->pluck('id', 'slug')->all();

        if ($categories === []) {
            $this->warn('Articles: no categories yet, nothing seeded (run CatalogSeeder first)');

            return;
        }

        DB::transaction(function () use ($articles, $categories): void {
            $seeded = $this->seededSlugs();

            foreach ($articles as $key => $data) {
                $data = (array) $data;
                $slug = Slug::make((string) ($data['slug'] ?? (is_string($key) ? $key : '')));

                if ($slug === '' || trim((string) ($data['title']['uz'] ?? '')) === '') {
                    $this->warn('Articles: an entry without a slug or an Uzbek title, skipped');

                    continue;
                }

                $existing = NewsPost::query()->where('slug', $slug)->first();

                if (in_array($slug, $seeded, true)) {
                    // Seeded before: never re-created; a still unlinked draft gets its categories.
                    if ($existing !== null) {
                        $this->linkIfUnlinked($existing, $data, $categories);
                    }

                    continue;
                }

                if ($existing !== null) {
                    if ($existing->kind === NewsKind::News) {
                        $this->warn("Articles: \"{$slug}\" is taken by a news post, skipped");
                    } else {
                        // An article with this slug already exists (made in admin): it is the admin's now.
                        $seeded[] = $slug;
                    }

                    continue;
                }

                $body = array_map(HtmlSanitizer::cleanOrNull(...), (array) ($data['body'] ?? []));

                if ($this->unsafe($data)) {
                    $this->warn("Articles: \"{$slug}\" has banned wording, skipped");

                    continue;
                }

                if (($field = $this->tooLong($data, $body)) !== null) {
                    $this->warn("Articles: \"{$slug}\" {$field} is too long, skipped");

                    continue;
                }

                $post = $this->createArticle($slug, $data, $body);
                $post->categories()->sync($this->categoryIds($slug, (array) ($data['categories'] ?? []), $categories));
                $seeded[] = $slug;

                if ((bool) ($data['confirm'] ?? false)) {
                    $this->warn("Articles: \"{$slug}\" has facts to confirm before publishing");
                }
            }

            $this->rememberSeeded($seeded);
        });

        // DatabaseSeeder runs without model events: flush the menus, sitemap and llms.txt here.
        NewsDirectory::flushAll();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $body  sanitized
     */
    private function createArticle(string $slug, array $data, array $body): NewsPost
    {
        $post = new NewsPost([
            'slug' => $slug,
            'kind' => NewsKind::Article,
            'status' => NewsStatus::Draft,
            'published_at' => null,
        ]);

        $post->storeTranslations('title', (array) $data['title']);
        $post->storeTranslations('excerpt', (array) ($data['excerpt'] ?? []));
        $post->storeTranslations('body', $body);
        $post->storeTranslations('meta_title', (array) ($data['meta_title'] ?? []));
        $post->storeTranslations('meta_description', (array) ($data['meta_description'] ?? []));

        // Set here what the saving hook would (DatabaseSeeder runs WithoutModelEvents).
        $post->locales = $post->translatedLocales();
        $post->content_updated_at = now();
        $post->save();

        return $post;
    }

    /**
     * A seeded article still a draft without any category gets the categories of its entry.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $categories  slug => id
     */
    private function linkIfUnlinked(NewsPost $post, array $data, array $categories): void
    {
        if ($post->kind !== NewsKind::Article || $post->status !== NewsStatus::Draft || $post->categories()->exists()) {
            return;
        }

        $post->categories()->sync($this->categoryIds($post->slug, (array) ($data['categories'] ?? []), $categories));
    }

    /**
     * The IDs of the known category slugs; unknown ones are reported.
     *
     * @param  array<int, mixed>  $slugs
     * @param  array<string, int>  $categories  slug => id
     * @return list<int>
     */
    private function categoryIds(string $article, array $slugs, array $categories): array
    {
        $ids = [];

        foreach ($slugs as $categorySlug) {
            $categorySlug = (string) $categorySlug;

            if (! isset($categories[$categorySlug])) {
                $this->warn("Articles: \"{$article}\" category \"{$categorySlug}\" not found, skipped");

                continue;
            }

            $ids[] = (int) $categories[$categorySlug];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function seededSlugs(): array
    {
        $value = Setting::query()->where('key', self::SEEDED_KEY)->value('value');
        $slugs = is_string($value) ? json_decode($value, true) : $value;

        return array_values(array_filter((array) $slugs, 'is_string'));
    }

    /**
     * @param  list<string>  $slugs
     */
    private function rememberSeeded(array $slugs): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::SEEDED_KEY],
            ['group' => 'system', 'value' => array_values(array_unique($slugs))],
        );
    }

    /**
     * The first field (with its locale) longer than the form allows, else null.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $body  sanitized
     */
    private function tooLong(array $data, array $body): ?string
    {
        foreach ([...self::MAX_LENGTHS, 'body' => NewsPost::BODY_MAX] as $field => $max) {
            $values = $field === 'body' ? $body : (array) ($data[$field] ?? []);

            foreach ($values as $locale => $text) {
                if (mb_strlen((string) $text) > $max) {
                    return "{$field} ({$locale})";
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function unsafe(array $data): bool
    {
        $texts = [];

        foreach (['title', 'excerpt', 'body', 'meta_title', 'meta_description'] as $field) {
            $texts = [...$texts, ...array_values((array) ($data[$field] ?? []))];
        }

        return BrandSafeText::violates(strip_tags(implode(' ', array_map('strval', $texts))));
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        $this->command?->warn($message);
    }
}
