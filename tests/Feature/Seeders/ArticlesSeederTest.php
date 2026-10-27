<?php

use App\Enums\NewsKind;
use App\Enums\NewsStatus;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Setting;
use Database\Seeders\ArticlesSeeder;
use Database\Seeders\CatalogSeeder;

/**
 * Write a temporary articles data file (removed in afterEach) and return its path.
 *
 * @param  list<array<string, mixed>>  $articles
 */
function articlesFixture(array $articles): string
{
    $base = tempnam(sys_get_temp_dir(), 'articles');
    $path = $base.'.php';
    file_put_contents($path, '<?php return '.var_export($articles, true).';');

    test()->articleFixtures = [...test()->articleFixtures, $base, $path];

    return $path;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function articleEntry(array $overrides = []): array
{
    return [
        'slug' => 'how-to-choose-a-scanner',
        'categories' => ['scanners'],
        'title' => ['uz' => 'Skanerni tanlash', 'ru' => 'Как выбрать сканер', 'en' => 'Choosing a scanner'],
        'excerpt' => ['uz' => 'Qisqacha.', 'ru' => 'Кратко.', 'en' => 'In short.'],
        'body' => ['uz' => '<p>Matn.</p><script>alert(1)</script>', 'ru' => '<p>Текст.</p>', 'en' => '<p>Text.</p>'],
        'meta_title' => ['uz' => 'Skaner', 'ru' => 'Сканер', 'en' => 'Scanner'],
        'meta_description' => ['uz' => 'Tavsif.', 'ru' => 'Описание.', 'en' => 'Description.'],
        ...$overrides,
    ];
}

/**
 * Run the seeder on a fixture and return it (for its warnings).
 *
 * @param  list<array<string, mixed>>  $articles
 */
function seedArticles(array $articles): ArticlesSeeder
{
    $seeder = new ArticlesSeeder;
    $seeder->run(articlesFixture($articles));

    return $seeder;
}

beforeEach(function () {
    $this->articleFixtures = [];
    $this->scanners = Category::factory()->create(['slug' => 'scanners']);
});

afterEach(function () {
    foreach ($this->articleFixtures as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('creates draft articles with sanitized bodies, locales and linked categories', function () {
    $seeder = seedArticles([articleEntry(['categories' => ['scanners', 'no-such-category']])]);

    $post = NewsPost::query()->sole();

    expect($post->kind)->toBe(NewsKind::Article)
        ->and($post->status)->toBe(NewsStatus::Draft)
        ->and($post->published_at)->toBeNull()
        ->and($post->content_updated_at)->not->toBeNull()
        ->and($post->locales)->toBe(['uz', 'ru', 'en'])
        ->and($post->getTranslation('body', 'uz'))->toContain('<p>Matn.</p>')->not->toContain('script')
        ->and($post->categories()->pluck('categories.id')->all())->toBe([$this->scanners->id])
        ->and($seeder->warnings)->toBe(['Articles: "how-to-choose-a-scanner" category "no-such-category" not found, skipped']);

    // Drafts are not public.
    $this->get('/uz/articles/how-to-choose-a-scanner')->assertNotFound();
});

it('is idempotent and never overwrites admin edits', function () {
    $entries = [articleEntry()];

    seedArticles($entries);

    $post = NewsPost::query()->sole();
    $post->update(['title' => ['uz' => 'Tahrirlangan sarlavha'], 'status' => NewsStatus::Published, 'published_at' => now()->subHour()]);

    $seeder = seedArticles($entries);

    expect(NewsPost::query()->count())->toBe(1)
        ->and($post->fresh()->getTranslation('title', 'uz'))->toBe('Tahrirlangan sarlavha')
        ->and($post->fresh()->status)->toBe(NewsStatus::Published)
        ->and($seeder->warnings)->toBe([]);
});

it('never re-creates a seeded article an admin deleted', function () {
    seedArticles([articleEntry()]);
    NewsPost::query()->sole()->delete();

    seedArticles([articleEntry()]);

    expect(NewsPost::query()->count())->toBe(0)
        ->and(Setting::query()->where('key', ArticlesSeeder::SEEDED_KEY)->sole()->value)->toBe(['how-to-choose-a-scanner']);
});

it('warns when a slug is taken by a news post and leaves the post alone', function () {
    $news = NewsPost::factory()->create(['slug' => 'how-to-choose-a-scanner']);

    $seeder = seedArticles([articleEntry()]);

    expect(NewsPost::query()->sole()->is($news))->toBeTrue()
        ->and($news->fresh()->kind)->toBe(NewsKind::News)
        ->and($seeder->warnings)->toBe(['Articles: "how-to-choose-a-scanner" is taken by a news post, skipped']);
});

it('stops without categories and links the categories of unlinked seeded drafts on a re-run', function () {
    Category::query()->delete();

    $seeder = seedArticles([articleEntry()]);

    expect(NewsPost::query()->count())->toBe(0)
        ->and($seeder->warnings)->toBe(['Articles: no categories yet, nothing seeded (run CatalogSeeder first)']);

    // Seeded while the category was missing: created unlinked; linked once the category exists.
    $printers = Category::factory()->create(['slug' => 'printers']);
    seedArticles([articleEntry(['categories' => ['scanners']])]);
    $post = NewsPost::query()->sole();

    expect($post->categories()->count())->toBe(0);

    $scanners = Category::factory()->create(['slug' => 'scanners']);
    seedArticles([articleEntry(['categories' => ['scanners']])]);

    expect($post->categories()->pluck('categories.id')->all())->toBe([$scanners->id]);

    // A linked or published article is never touched again.
    $post->categories()->sync([$printers->id]);
    seedArticles([articleEntry(['categories' => ['scanners']])]);

    expect($post->categories()->pluck('categories.id')->all())->toBe([$printers->id]);
});

it('reports articles with facts to confirm and skips banned wording anywhere, the body included', function () {
    $seeder = seedArticles([
        articleEntry(['slug' => 'to-confirm', 'confirm' => true]),
        articleEntry(['slug' => 'cheap-title', 'title' => ['uz' => 'Eng arzon skanerlar', 'ru' => 'Самые дешевые сканеры', 'en' => 'Cheapest scanners']]),
        articleEntry(['slug' => 'cheap-body', 'body' => ['uz' => '<p>Matn.</p>', 'ru' => '<p>У нас скидки на сканеры.</p>', 'en' => '<p>Text.</p>']]),
    ]);

    expect(NewsPost::query()->pluck('slug')->all())->toBe(['to-confirm'])
        ->and($seeder->warnings)->toBe([
            'Articles: "to-confirm" has facts to confirm before publishing',
            'Articles: "cheap-title" has banned wording, skipped',
            'Articles: "cheap-body" has banned wording, skipped',
        ])
        ->and(NewsPost::query()->sole()->getTranslation('title', 'uz'))->not->toContain('[confirm]');
});

it('skips texts longer than the admin form allows', function () {
    $seeder = seedArticles([
        articleEntry(['slug' => 'long-title', 'title' => ['uz' => str_repeat('a', 256)]]),
        articleEntry(['slug' => 'long-excerpt', 'excerpt' => ['ru' => str_repeat('б', 501)]]),
        articleEntry(['slug' => 'long-body', 'body' => ['uz' => '<p>'.str_repeat('a', NewsPost::BODY_MAX).'</p>']]),
    ]);

    expect(NewsPost::query()->count())->toBe(0)
        ->and($seeder->warnings)->toBe([
            'Articles: "long-title" title (uz) is too long, skipped',
            'Articles: "long-excerpt" excerpt (ru) is too long, skipped',
            'Articles: "long-body" body (uz) is too long, skipped',
        ]);
});

it('does nothing when the data file is missing', function () {
    $seeder = new ArticlesSeeder;

    $seeder->run(sys_get_temp_dir().'/no-articles-here.php');

    expect(NewsPost::query()->count())->toBe(0)
        ->and($seeder->warnings)->toBe(['Articles: no-articles-here.php not found, nothing seeded']);
});

it('seeds the real data after CatalogSeeder: drafts, each in a category, only the expected notes', function () {
    Category::query()->delete();
    $this->seed(CatalogSeeder::class);

    $seeder = new ArticlesSeeder;
    $seeder->run();

    $articles = NewsPost::query()->ofKind(NewsKind::Article)->withCount('categories')->get();
    $expected = collect(require database_path(ArticlesSeeder::DATA_PATH))
        ->filter(fn (array $entry): bool => (bool) ($entry['confirm'] ?? false))
        ->map(fn (array $entry): string => "Articles: \"{$entry['slug']}\" has facts to confirm before publishing")
        ->values()
        ->all();

    expect($articles)->toHaveCount(count(require database_path(ArticlesSeeder::DATA_PATH)))
        ->and($articles->every(fn (NewsPost $post): bool => $post->status === NewsStatus::Draft && $post->published_at === null))->toBeTrue()
        ->and($articles->every(fn (NewsPost $post): bool => $post->categories_count >= 1))->toBeTrue()
        ->and($seeder->warnings)->toBe($expected);
})->skip(fn (): bool => ! is_file(database_path(ArticlesSeeder::DATA_PATH)), 'articles.php not written yet');
