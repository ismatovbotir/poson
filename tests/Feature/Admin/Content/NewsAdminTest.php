<?php

use App\Actions\News\SaveNewsPost;
use App\Enums\NewsState;
use App\Enums\NewsStatus;
use App\Enums\Role;
use App\Livewire\Admin\Ai\Assistant;
use App\Livewire\Admin\News\Form;
use App\Livewire\Admin\News\Index;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Support\Admin\Menu;
use App\Support\Admin\MenuGroup;
use App\Support\Admin\MenuItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * SaveNewsPost input with defaults.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newsData(array $overrides = []): array
{
    return [
        'title' => ['uz' => 'Yangi skanerlar'],
        'excerpt' => [],
        'body' => ['uz' => '<p>Matn.</p>'],
        'meta_title' => [],
        'meta_description' => [],
        'slug' => null,
        'status' => 'published',
        'published_at' => null,
        'product_ids' => [],
        'category_ids' => [],
        ...$overrides,
    ];
}

describe('access', function () {
    it('applies the news policy per role', function (Role $role, bool $allowed) {
        $user = staffUser($role);
        $post = NewsPost::factory()->create();

        expect($user->can('viewAny', NewsPost::class))->toBe($allowed)
            ->and($user->can('view', $post))->toBe($allowed)
            ->and($user->can('create', NewsPost::class))->toBe($allowed)
            ->and($user->can('update', $post))->toBe($allowed)
            ->and($user->can('delete', $post))->toBe($allowed);
    })->with([
        'admin' => [Role::Admin, true],
        'content manager' => [Role::ContentManager, true],
        'sales manager' => [Role::SalesManager, false],
        'service engineer' => [Role::ServiceEngineer, false],
    ]);

    it('opens the news screens for admin and content manager', function (Role $role) {
        $post = NewsPost::factory()->create();

        $this->actingAs(staffUser($role));
        $this->get(route('admin.news.index'))->assertOk()->assertSeeLivewire(Index::class)->assertSee((string) $post->title);
        $this->get(route('admin.news.create'))->assertOk();
        $this->get(route('admin.news.edit', $post))->assertOk();
    })->with(catalogWriterRoles());

    it('forbids the news screens and writes to sales managers and service engineers', function (Role $role) {
        $post = NewsPost::factory()->create();
        $user = staffUser($role);

        $this->actingAs($user);
        $this->get(route('admin.news.index'))->assertForbidden();
        $this->get(route('admin.news.create'))->assertForbidden();
        $this->get(route('admin.news.edit', $post))->assertForbidden();

        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
    })->with(catalogViewOnlyRoles());

    it('links the news menu item (no longer "soon")', function () {
        $item = collect(Menu::definition())
            ->flatMap(fn (MenuGroup $group): array => $group->items)
            ->first(fn (MenuItem $item): bool => $item->key === 'news');

        expect($item->isSoon())->toBeFalse();

        $this->actingAs(staffUser(Role::ContentManager))
            ->get('/admin')
            ->assertOk()
            ->assertSee('href="'.route('admin.news.index').'"', false);
    });
});

describe('index', function () {
    it('filters by state, searches and sorts', function () {
        NewsPost::factory()->draft()->create(['title' => ['uz' => 'Qoralama xabar']]);
        NewsPost::factory()->scheduled()->create(['title' => ['uz' => 'Kelasi hafta']]);
        NewsPost::factory()->published(now()->subDays(5))->create(['title' => ['uz' => 'Eski xabar']]);
        NewsPost::factory()->published(now()->subDay())->create(['title' => ['uz' => 'Yangi xabar']]);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->assertSeeHtml('data-news-state-badge="scheduled"')
            ->assertSeeInOrder(['Kelasi hafta', 'Yangi xabar', 'Eski xabar', 'Qoralama xabar'])
            ->set('sort', 'oldest')
            ->assertSeeInOrder(['Eski xabar', 'Yangi xabar', 'Kelasi hafta'])
            ->set('status', 'scheduled')
            ->assertSee('Kelasi hafta')
            ->assertDontSee('Yangi xabar')
            ->set('status', 'draft')
            ->assertSee('Qoralama xabar')
            ->assertDontSee('Kelasi hafta')
            ->set('status', 'published')
            ->assertSee('Yangi xabar')
            ->assertSee('Eski xabar')
            ->assertDontSee('Kelasi hafta')
            ->assertDontSee('Qoralama xabar')
            ->set('status', '')
            ->set('search', 'eski')
            ->assertSee('Eski xabar')
            ->assertDontSee('Yangi xabar');
    });

    it('ignores an unknown sort key', function () {
        NewsPost::factory()->create(['title' => ['uz' => 'Bitta xabar']]);

        Livewire::actingAs(staffUser())
            ->test(Index::class)
            ->set('sort', 'title; drop table news_posts')
            ->assertOk()
            ->assertSee('Bitta xabar');
    });

    it('deletes a post with confirmation data and forbids it without permission', function () {
        $post = NewsPost::factory()->create(['slug' => 'old-news']);
        $post->products()->attach(Product::factory()->create());

        Livewire::actingAs(staffUser(Role::SalesManager))
            ->test(Index::class)
            ->assertForbidden();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->assertSeeHtml('data-delete-news="old-news"')
            ->call('delete', $post->id)
            ->assertDispatched('toast');

        expect(NewsPost::query()->count())->toBe(0)
            ->and(DB::table('news_post_product')->count())->toBe(0);
    });
});

describe('form', function () {
    it('creates a published post with an auto slug from the uz title, a sanitized body and relations', function () {
        $this->freezeSecond();
        $user = staffUser(Role::ContentManager);
        $product = Product::factory()->create();
        $category = Category::factory()->create();

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('form.title.uz', 'Yangi Zebra skanerlari')
            ->set('form.title.en', 'New Zebra scanners')
            ->set('form.excerpt.uz', 'Omborda.')
            ->set('form.body.uz', '<p onclick="x()">Matn <a href="https://example.com">havola</a></p><script>alert(1)</script>')
            ->set('form.status', 'published')
            ->call('addProduct', $product->id)
            ->call('addCategory', $category->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $post = NewsPost::query()->sole();

        expect($post->slug)->toBe('yangi-zebra-skanerlari')
            ->and($post->status)->toBe(NewsStatus::Published)
            ->and($post->published_at->equalTo(now()))->toBeTrue()
            ->and($post->author_id)->toBe($user->id)
            ->and($post->locales)->toBe(['uz'])
            ->and($post->content_updated_at)->not->toBeNull()
            ->and($post->getTranslation('body', 'uz'))->toBe('<p>Matn <a href="https://example.com" rel="nofollow">havola</a></p>')
            ->and($post->products()->pluck('products.id')->all())->toBe([$product->id])
            ->and($post->categories()->pluck('categories.id')->all())->toBe([$category->id]);
    });

    it('stores the Tashkent publication time in UTC and shows it back in Tashkent time', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->set('form.title.uz', 'Rejalashtirilgan')
            ->set('form.body.uz', '<p>Matn</p>')
            ->set('form.status', 'published')
            ->set('form.published_at', '2030-05-10T14:30')
            ->call('save')
            ->assertHasNoErrors();

        $post = NewsPost::query()->sole();

        expect($post->published_at->utc()->format('Y-m-d H:i'))->toBe('2030-05-10 09:30')
            ->and($post->state())->toBe(NewsState::Scheduled);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['newsPost' => $post])
            ->assertSet('form.published_at', '2030-05-10T14:30');
    });

    it('validates the title, banned wording and the date, and never takes the slug from the client', function () {
        NewsPost::factory()->create(['slug' => 'taken-slug']);

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', '')
            ->set('form.excerpt.ru', 'Скидки каждый день')
            ->set('form.meta_title.en', 'Cheapest scanners')
            ->set('form.published_at', 'tomorrow')
            ->set('form.status', 'archived')
            ->call('save')
            ->assertHasErrors(['form.title.uz', 'form.excerpt.ru', 'form.meta_title.en', 'form.published_at', 'form.status']);

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Xabar')
            ->set('form.slug', 'taken-slug')
            ->call('save')
            ->assertHasNoErrors();

        expect(NewsPost::query()->count())->toBe(2)
            ->and(NewsPost::query()->latest('id')->first()->slug)->toBe('xabar');
    });

    it('rejects banned wording in the body through the action', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Xabar')
            ->set('form.body.ru', '<p>Самые <strong>дешёвые</strong> принтеры</p>')
            ->call('save')
            ->assertHasErrors(['form.body.ru']);

        expect(NewsPost::query()->count())->toBe(0);
    });

    it('refuses to publish a post without any translated locale, but keeps it as a draft', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Faqat sarlavha')
            ->set('form.status', 'published')
            ->call('save')
            ->assertHasErrors(['form.body.uz']);

        expect(NewsPost::query()->count())->toBe(0);

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Faqat sarlavha')
            ->set('form.status', 'draft')
            ->call('save')
            ->assertHasNoErrors();

        expect(NewsPost::query()->sole())
            ->status->toBe(NewsStatus::Draft)
            ->published_at->toBeNull()
            ->locales->toBe([]);
    });

    it('keeps slugs unique in the action, numbering auto slugs', function () {
        $save = app(SaveNewsPost::class);

        $first = $save->handle(null, newsData());
        $second = $save->handle(null, newsData());

        expect($first->slug)->toBe('yangi-skanerlar')
            ->and($second->slug)->toBe('yangi-skanerlar-2');

        // An incoming slug is ignored: generated on create, kept on update.
        expect($save->handle(null, newsData(['slug' => 'yangi-skanerlar']))->slug)->toBe('yangi-skanerlar-3')
            ->and($save->handle($first->fresh(), newsData(['slug' => 'forged', 'title' => ['uz' => 'Boshqa sarlavha', 'ru' => '', 'en' => '']]))->slug)->toBe('yangi-skanerlar');
    });

    it('updates the post and its relations, keeping the author and moving the content date only on text changes', function () {
        $this->freezeSecond();
        $author = staffUser(Role::ContentManager);
        $editor = staffUser();
        $product = Product::factory()->create();
        $post = app(SaveNewsPost::class)->handle(null, newsData(['product_ids' => [$product->id]]), $author);
        $created = $post->content_updated_at->toImmutable();

        $this->travel(1)->day();
        Livewire::actingAs($editor)
            ->test(Form::class, ['newsPost' => $post])
            ->assertSee($product->sku)
            ->call('removeProduct', $product->id)
            ->set('form.meta_title.ru', 'Новые сканеры — Poson')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $post->refresh();
        expect($post->author_id)->toBe($author->id)
            ->and($post->products()->count())->toBe(0)
            ->and($post->content_updated_at->equalTo($created))->toBeTrue();

        $this->travel(1)->day();
        Livewire::actingAs($editor)
            ->test(Form::class, ['newsPost' => $post])
            ->set('form.excerpt.uz', 'Yangi qisqacha')
            ->call('save')
            ->assertHasNoErrors();

        expect($post->refresh()->content_updated_at->equalTo(now()))->toBeTrue();
    });

    it('searches products and categories in the pickers', function () {
        $product = Product::factory()->create(['sku' => 'ZB-DS2208']);
        $category = Category::factory()->create(['name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners'], 'slug' => 'scanners']);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->set('productSearch', 'zb-ds')
            ->assertSeeHtml('data-news-product-results')
            ->assertSee('ZB-DS2208')
            ->call('addProduct', $product->id)
            ->assertSet('form.product_ids', [$product->id])
            ->assertSet('productSearch', '')
            ->call('addProduct', $product->id)
            ->assertSet('form.product_ids', [$product->id])
            ->set('categorySearch', 'scan')
            ->assertSeeHtml('data-news-category-results')
            ->call('addCategory', $category->id)
            ->assertSet('form.category_ids', [$category->id])
            ->call('addCategory', 999999)
            ->assertSet('form.category_ids', [$category->id]);
    });

    it('rejects related IDs that do not exist', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Xabar')
            ->set('form.product_ids', [999999])
            ->set('form.category_ids', [888888])
            ->call('save')
            ->assertHasErrors(['form.product_ids.0', 'form.category_ids.0']);
    });

    it('stores a cover image privately with WebP conversions and alt per locale', function () {
        Storage::fake('public');
        Storage::fake('local');
        $post = NewsPost::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['newsPost' => $post])
            ->set('form.image', UploadedFile::fake()->image('cover.jpg', 1600, 900))
            ->set('form.image_alt.uz', 'Yangi skaner')
            ->call('save')
            ->assertHasNoErrors();

        $media = $post->fresh()->getFirstMedia('cover');

        expect($media)->not->toBeNull()
            ->and($media->disk)->toBe('local')
            ->and($post->fresh()->imageUrl('large'))->toEndWith('.webp')
            ->and($post->fresh()->imageAlt('uz'))->toBe('Yangi skaner');
    });

    it('deletes the post from the form', function () {
        $post = NewsPost::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['newsPost' => $post])
            ->assertSeeHtml('data-delete-news')
            ->call('delete')
            ->assertRedirect(route('admin.news.index'));

        expect(NewsPost::query()->count())->toBe(0);
    });
});

describe('review follow-ups', function () {
    it('refuses publication dates outside 2000–2099 in the form and the action', function (string $typed) {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Xabar')
            ->set('form.body.uz', '<p>Matn</p>')
            ->set('form.published_at', $typed)
            ->call('save')
            ->assertHasErrors(['form.published_at'])
            ->assertSee(__('admin.news.errors.published_at_range'));

        expect(fn () => app(SaveNewsPost::class)->handle(null, newsData(['published_at' => now()->setDate(1990, 1, 1)])))
            ->toThrow(ValidationException::class, __('admin.news.errors.published_at_range'));

        expect(NewsPost::query()->count())->toBe(0);
    })->with(['1999-12-31T23:00', '2100-01-01T10:00', '0202-05-01T10:00']);

    it('keeps the stored seconds while the typed minute is unchanged', function () {
        $post = NewsPost::factory()->published(now()->setDate(2026, 3, 5)->setTime(9, 15, 42))->create();

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['newsPost' => $post])
            ->assertSet('form.published_at', '2026-03-05T14:15')
            ->set('form.title.ru', 'Новый заголовок')
            ->call('save')
            ->assertHasNoErrors();

        expect($post->refresh()->published_at->format('Y-m-d H:i:s'))->toBe('2026-03-05 09:15:42');

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['newsPost' => $post])
            ->set('form.published_at', '2026-03-05T14:20')
            ->call('save')
            ->assertHasNoErrors();

        expect($post->refresh()->published_at->format('Y-m-d H:i:s'))->toBe('2026-03-05 09:20:00');
    });

    it('regenerates a slug taken concurrently once', function () {
        $clashed = false;

        NewsPost::creating(function (NewsPost $post) use (&$clashed): void {
            if (! $clashed) {
                $clashed = true;
                NewsPost::factory()->create(['slug' => $post->slug]);
            }
        });

        expect(app(SaveNewsPost::class)->handle(null, newsData())->slug)->toBe('yangi-skanerlar-2');
    });

    it('turns a slug taken concurrently on the retry too into a slug error', function () {
        NewsPost::creating(function (NewsPost $post): void {
            DB::table('news_posts')->insert([
                'slug' => $post->slug,
                'status' => 'draft',
                'title' => json_encode(['uz' => 'Parallel']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        expect(fn () => app(SaveNewsPost::class)->handle(null, newsData()))
            ->toThrow(ValidationException::class, __('validation.unique', ['attribute' => __('admin.catalog.slug')]));
    });

    it('shows the slug read-only: a live preview from the uz title while new, locked once saved', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->assertDontSeeHtml('wire:model="form.slug"')
            ->assertSeeHtml('data-slug-preview')
            ->set('form.title.uz', 'Yangi ofis ochildi')
            ->assertSee('yangi-ofis-ochildi');

        $post = NewsPost::factory()->create(['slug' => 'stored-news']);

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['newsPost' => $post])
            ->assertSeeHtml('data-slug-locked')
            ->assertDontSeeHtml('data-slug-warning')
            ->set('form.title.uz', 'Butunlay boshqa sarlavha')
            ->set('form.slug', 'forged')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.slug', 'stored-news');

        expect($post->fresh()->slug)->toBe('stored-news');
    });

    it('rejects banned wording in the cover alt (shared image action)', function () {
        Storage::fake('public');
        Storage::fake('local');
        $post = NewsPost::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['newsPost' => $post])
            ->set('form.image', UploadedFile::fake()->image('cover.jpg', 1600, 900))
            ->set('form.image_alt.ru', 'Скидки на сканеры')
            ->call('save')
            ->assertHasErrors(['form.image_alt.ru']);

        expect($post->fresh()->getFirstMedia('cover'))->toBeNull();
    });

    it('labels the derived state with the NewsState enum', function () {
        expect(NewsPost::factory()->draft()->make()->state())->toBe(NewsState::Draft)
            ->and(NewsPost::factory()->scheduled()->make()->state())->toBe(NewsState::Scheduled)
            ->and(NewsPost::factory()->make()->state())->toBe(NewsState::Published)
            ->and(NewsState::Scheduled->label())->toBe(__('enums.news_state.scheduled'))
            ->and(NewsState::Scheduled->color())->toBe('sky');
    });

    it('forbids save and delete when the permission is revoked after mount', function (string $method) {
        $user = staffUser(Role::ContentManager);
        $post = NewsPost::factory()->create(['title' => ['uz' => 'Asl sarlavha']]);

        $component = Livewire::actingAs($user)
            ->test(Form::class, ['newsPost' => $post])
            ->set('form.title.uz', 'Oʻzgartirilgan');

        $user->syncRoles([Role::SalesManager->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call($method)->assertForbidden();

        expect($post->fresh())->not->toBeNull()
            ->and($post->fresh()->getTranslation('title', 'uz'))->toBe('Asl sarlavha');
    })->with(['save', 'delete']);

    it('forbids the index delete when the permission is revoked after mount', function () {
        $user = staffUser(Role::ContentManager);
        $post = NewsPost::factory()->create();

        $component = Livewire::actingAs($user)->test(Index::class);

        $user->syncRoles([Role::SalesManager->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('delete', $post->id)->assertForbidden();

        expect($post->fresh())->not->toBeNull();
    });
});

describe('SI assist', function () {
    beforeEach(function () {
        enableAiAssist();
        fakeTextAssistant();
    });

    it('offers SI on title, excerpt, body and SEO', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->assertSeeHtml('data-ai-button="news.title"')
            ->assertSeeHtml('data-ai-button="news.excerpt"')
            ->assertSeeHtml('data-ai-button="news.body"')
            ->assertSeeHtml('data-ai-button="news.meta_title"')
            ->assertSeeHtml('data-ai-button="news.meta_description"');
    });

    it('builds the context server-side: stored subject and slug, related names from the database', function () {
        $post = NewsPost::factory()->create(['slug' => 'stored-slug']);
        $product = Product::factory()->create(['sku' => 'HW-1', 'name' => ['uz' => 'Skaner', 'en' => 'Scanner X']]);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['newsPost' => $post])
            ->set('form.slug', 'forged')
            ->set('form.product_ids', [$product->id])
            ->call('openAi', 'form.body', 'ru')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'news.body'
                && $params['context']['subject_type'] === 'news_post'
                && $params['context']['subject_id'] === $post->id
                && $params['context']['facts']['News post'] === 'news/stored-slug'
                && $params['context']['facts']['Related products'] === 'Scanner X (HW-1)');
    });

    it('keeps a news subject only for a news field and a user who may view it', function () {
        $post = NewsPost::factory()->create();
        $page = Page::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Assistant::class)
            ->call('openFor', 'form-1', 'form.title', 'news.title', 'uz', ['name' => 'X', 'subject_type' => 'news_post', 'subject_id' => $post->id], [])
            ->assertSet('open', true)
            ->assertSet('context.subject_type', 'news_post')
            ->assertSet('context.subject_id', $post->id);

        // A forged subject of another kind is dropped.
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Assistant::class)
            ->call('openFor', 'form-1', 'form.title', 'news.title', 'uz', ['name' => 'X', 'subject_type' => 'page', 'subject_id' => $page->id], [])
            ->assertSet('context.subject_type', null)
            ->assertSet('context.subject_id', null);
    });

    it('applies AI text only to whitelisted news targets', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->call('openAi', 'form.slug', 'uz')
            ->assertNotDispatched('ai-open')
            ->call('openAi', 'form.excerpt', 'uz')
            ->assertDispatched('ai-open', field: 'news.excerpt');
    });
});
