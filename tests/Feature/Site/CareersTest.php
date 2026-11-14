<?php

use App\Enums\VacancyDepartment;
use App\Models\NewsPost;
use App\Models\Vacancy;
use App\Support\LocaleUrl;
use App\Support\Site\Navigation;
use Illuminate\Support\Arr;

it('lists only published, not expired vacancies in admin order', function () {
    Vacancy::factory()->published()->create(['title' => ['uz' => 'Ikkinchi', 'ru' => 'Второй', 'en' => 'Second'], 'sort' => 2]);
    Vacancy::factory()->published()->create(['title' => ['uz' => 'Birinchi', 'ru' => 'Первый', 'en' => 'First'], 'sort' => 1]);
    Vacancy::factory()->create(['title' => ['uz' => 'Qoralama', 'ru' => 'Черновик', 'en' => 'Draft']]);
    Vacancy::factory()->expired()->create(['title' => ['uz' => 'Eskirgan', 'ru' => 'Старая', 'en' => 'Old']]);
    Vacancy::factory()->published()->create(['title' => ['uz' => 'Bugun', 'ru' => 'Сегодня', 'en' => 'Today'], 'sort' => 3, 'valid_through' => now('Asia/Tashkent')->toDateString()]);

    $this->get('/uz/careers')
        ->assertOk()
        ->assertSeeInOrder(['Birinchi', 'Ikkinchi', 'Bugun'])
        ->assertDontSee('Qoralama')
        ->assertDontSee('Eskirgan');
});

it('shows the vacancy page with escaped plain text and no fallback between locales', function () {
    $vacancy = Vacancy::factory()->published()->create([
        'description' => ['uz' => "<img src=x onerror=alert(1)>\n\nIkkinchi", 'ru' => 'Р', 'en' => 'E'],
        'requirements' => ['uz' => ['<b>x</b>'], 'ru' => ['Опыт'], 'en' => ['Exp']],
    ]);

    $this->get($vacancy->url('uz'))
        ->assertOk()
        ->assertDontSee('<img src=x', false)
        ->assertSee('&lt;img src=x', false)
        ->assertSee('&lt;b&gt;x&lt;/b&gt;', false)
        ->assertSee(__('careers.site.how_to_apply_title', [], 'uz'));
});

it('answers 404 for a draft, an expired and an unknown slug alike', function () {
    $draft = Vacancy::factory()->create();
    $expired = Vacancy::factory()->expired()->create();

    $bodies = collect([$draft->slug_uz, $expired->slug_uz, 'no-such-vacancy'])->map(fn (string $slug) => $this->get("/uz/careers/{$slug}")->assertNotFound()->getContent());

    expect($bodies->unique()->count())->toBe(1);
    // Another locale's slug of a draft must not redirect either.
    $this->get("/ru/careers/{$draft->slug_uz}")->assertNotFound();
});

it('redirects a slug of another locale with 301 to this locale slug', function () {
    $vacancy = Vacancy::factory()->published()->create();

    $this->get("/ru/careers/{$vacancy->slug_uz}")->assertStatus(301)->assertRedirect($vacancy->url('ru'));
    $this->get("/en/careers/{$vacancy->slug_ru}")->assertStatus(301)->assertRedirect($vacancy->url('en'));
    $this->get("/ru/careers/{$vacancy->slug_ru}")->assertOk();
});

it('builds hreflang, canonical and the language switcher from the per-locale slugs', function () {
    $vacancy = Vacancy::factory()->published()->create();

    $html = $this->get($vacancy->url('ru'))->assertOk()->getContent();

    foreach (['uz', 'ru', 'en'] as $locale) {
        expect($html)->toContain('hreflang="'.$locale.'" href="'.$vacancy->url($locale).'"');
    }

    expect($html)->toContain('rel="canonical" href="'.$vacancy->url('ru').'"')
        ->and($html)->toContain('href="'.$vacancy->url('en').'"');
});

it('accepts only supported locale keys and site URLs in LocaleUrl::useUrls', function () {
    $this->get('/uz/careers')->assertOk();
    LocaleUrl::useUrls(['uz' => url('/uz/careers/a'), 'de' => url('/de/x'), 'ru' => 'https://evil.example/x', 'en' => 'javascript:alert(1)']);

    expect(LocaleUrl::alternates())->toHaveKey('uz', url('/uz/careers/a'))
        ->and(LocaleUrl::alternates()['ru'])->not->toContain('evil.example')
        ->and(LocaleUrl::alternates())->not->toHaveKey('de')
        ->and(LocaleUrl::switch('en'))->not->toContain('javascript');
});

it('keeps the news and catalog switchers unchanged', function () {
    $post = NewsPost::factory()->create();

    $this->get($post->url('uz'))->assertOk();
    expect(LocaleUrl::switch('ru'))->toBe(url('/ru/news/'.$post->slug));
});

it('always shows the careers nav item and footer link, even with no vacancy', function () {
    expect(Vacancy::query()->count())->toBe(0);

    foreach (['uz' => 'Vakansiyalar', 'ru' => 'Вакансии', 'en' => 'Careers'] as $locale => $label) {
        app()->setLocale($locale);
        request()->attributes->remove('site.nav.main.'.$locale);

        expect(array_column(Navigation::main(), 'key'))->toContain('careers')
            ->and(collect(Navigation::main())->firstWhere('key', 'careers')['label'])->toBe($label)
            ->and(array_column(Navigation::customerPages(), 'key'))->toContain('careers');
    }

    $this->get('/ru/careers')->assertOk()->assertSee('/ru/careers', false);
});

it('puts careers after articles in the main nav', function () {
    $keys = array_column(Navigation::main(), 'key');

    expect(array_search('careers', $keys, true))->toBeGreaterThan(array_search('services', $keys, true));
});

it('shows the open application block with a link per department and no noindex on the list', function () {
    openSearchIndexing();
    $response = $this->get('/en/careers')->assertOk()
        ->assertSee(__('careers.site.empty', [], 'en'))
        ->assertSee('data-careers-open', false);

    foreach (VacancyDepartment::cases() as $department) {
        $response->assertSee('/en/careers/apply/'.$department->value, false);
    }

    expect($response->getContent())->not->toMatch('/<meta name="robots"[^>]*noindex/');
});

it('renders the open application page per department, noindex, and 404s an unknown department', function () {
    openSearchIndexing();
    $this->get('/uz/careers/apply/sales')->assertOk()->assertSee('<meta name="robots" content="noindex,follow">', false)->assertSee('data-open-application="sales"', false);
    $this->get('/uz/careers/apply/nope')->assertNotFound();
    $this->get('/uz/careers/apply/Sales')->assertNotFound();
});

it('has uz, ru and en lines for every careers key', function () {
    $keys = fn (string $locale) => collect(Arr::dot(require lang_path("{$locale}/careers.php")))->keys()->sort()->values()->all();

    expect($keys('ru'))->toBe($keys('uz'))->and($keys('en'))->toBe($keys('uz'));
});
