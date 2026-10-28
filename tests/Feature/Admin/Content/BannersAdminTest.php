<?php

use App\Actions\Banners\SaveBanner;
use App\Enums\BannerPlacement;
use App\Enums\BannerState;
use App\Enums\Role;
use App\Livewire\Admin\Banners\Form;
use App\Livewire\Admin\Banners\Index;
use App\Models\Banner;
use App\Models\Category;
use App\Support\Admin\Menu;
use App\Support\Admin\MenuGroup;
use App\Support\Admin\MenuItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * A valid desktop upload (at least 1200 × 375).
 */
function bannerDesktopUpload(int $width = 1920, int $height = 600): UploadedFile
{
    return UploadedFile::fake()->image('desktop.jpg', $width, $height);
}

/**
 * SaveBanner input with defaults.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bannerData(array $overrides = []): array
{
    return [
        'placement' => 'home_hero',
        'category_id' => null,
        'title' => ['uz' => 'Servis markazi'],
        'text' => [],
        'button_label' => [],
        'link' => '/catalog',
        'starts_at' => null,
        'ends_at' => null,
        'is_active' => true,
        ...$overrides,
    ];
}

describe('access', function () {
    it('applies the banner policy per role', function (Role $role, bool $allowed) {
        $user = staffUser($role);
        $banner = Banner::factory()->create();

        expect($user->can('viewAny', Banner::class))->toBe($allowed)
            ->and($user->can('view', $banner))->toBe($allowed)
            ->and($user->can('create', Banner::class))->toBe($allowed)
            ->and($user->can('update', $banner))->toBe($allowed)
            ->and($user->can('delete', $banner))->toBe($allowed);
    })->with([
        'admin' => [Role::Admin, true],
        'content manager' => [Role::ContentManager, true],
        'sales manager' => [Role::SalesManager, false],
        'service engineer' => [Role::ServiceEngineer, false],
    ]);

    it('opens the banner screens for admin and content manager', function (Role $role) {
        $banner = Banner::factory()->withImages()->create(['title' => ['uz' => 'Kafolat banneri']]);

        $this->actingAs(staffUser($role));
        $this->get(route('admin.banners.index'))->assertOk()->assertSeeLivewire(Index::class)->assertSee('Kafolat banneri');
        $this->get(route('admin.banners.create'))->assertOk()->assertSee('data-no-text-hint', false);
        $this->get(route('admin.banners.edit', $banner))->assertOk();
    })->with(catalogWriterRoles());

    it('forbids the banner screens and writes to sales managers and service engineers', function (Role $role) {
        $banner = Banner::factory()->create();
        $user = staffUser($role);

        $this->actingAs($user);
        $this->get(route('admin.banners.index'))->assertForbidden();
        $this->get(route('admin.banners.create'))->assertForbidden();
        $this->get(route('admin.banners.edit', $banner))->assertForbidden();

        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
        Livewire::actingAs($user)->test(Form::class)->assertForbidden();
    })->with(catalogViewOnlyRoles());

    it('links the banners menu item (no longer "soon")', function () {
        $item = collect(Menu::definition())
            ->flatMap(fn (MenuGroup $group): array => $group->items)
            ->first(fn (MenuItem $item): bool => $item->key === 'banners');

        expect($item->isSoon())->toBeFalse();

        $this->actingAs(staffUser(Role::ContentManager))
            ->get('/admin')
            ->assertOk()
            ->assertSee('href="'.route('admin.banners.index').'"', false);
    });
});

describe('index', function () {
    it('shows the thumbnail and state, and filters by placement, category, state and title', function () {
        $category = Category::factory()->create();
        Banner::factory()->withImages()->active()->create(['title' => ['uz' => 'Faol banner'], 'sort' => 1]);
        Banner::factory()->scheduled()->create(['title' => ['uz' => 'Kelgusi banner'], 'sort' => 2]);
        Banner::factory()->expired()->create(['title' => ['uz' => 'Eski banner'], 'sort' => 3]);
        Banner::factory()->inactive()->create(['title' => ['uz' => 'Oʻchiq banner'], 'sort' => 4]);
        Banner::factory()->category($category)->create(['title' => ['uz' => 'Kategoriya banneri']]);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->assertSeeHtml('data-banner-thumb')
            ->assertSeeHtml('data-banner-state-badge="scheduled"')
            ->assertSeeHtml('data-banner-state-badge="expired"')
            ->assertSeeHtml('data-banner-state-badge="inactive"')
            ->assertSeeInOrder(['Faol banner', 'Kelgusi banner', 'Eski banner', 'Oʻchiq banner', 'Kategoriya banneri'])
            ->set('status', BannerState::Scheduled->value)
            ->assertSee('Kelgusi banner')
            ->assertDontSee('Faol banner')
            ->set('status', BannerState::Expired->value)
            ->assertSee('Eski banner')
            ->assertDontSee('Kelgusi banner')
            ->set('status', BannerState::Active->value)
            ->assertSee('Faol banner')
            ->assertSee('Kategoriya banneri')
            ->assertDontSee('Eski banner')
            ->assertDontSee('Oʻchiq banner')
            ->set('status', '')
            ->set('placement', BannerPlacement::Category->value)
            ->assertSeeHtml('data-banner-category-filter')
            ->assertSee('Kategoriya banneri')
            ->assertDontSee('Faol banner')
            ->set('categoryId', (string) $category->id)
            ->assertSee('Kategoriya banneri')
            ->set('placement', '')
            ->assertSet('categoryId', '')
            ->set('search', 'eski')
            ->assertSee('Eski banner')
            ->assertDontSee('Faol banner');
    });

    it('moves a banner within its placement only', function () {
        $first = Banner::factory()->create(['sort' => 1]);
        $second = Banner::factory()->create(['sort' => 2]);
        $third = Banner::factory()->create(['sort' => 3]);
        $other = Banner::factory()->category()->create(['sort' => 1]);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->call('move', $third->id, -1)
            ->call('move', $first->id, -1);

        expect(Banner::query()->placedAt(BannerPlacement::HomeHero)->ordered()->pluck('id')->all())->toBe([$first->id, $third->id, $second->id])
            ->and($other->fresh()->sort)->toBe(1);
    });

    it('deletes a banner with its images and forbids moving or deleting without permission', function () {
        $banner = Banner::factory()->withImages()->create();

        Livewire::actingAs(staffUser(Role::SalesManager))
            ->test(Index::class)
            ->assertForbidden();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->assertSeeHtml('data-delete-banner="'.$banner->id.'"')
            ->call('delete', $banner->id)
            ->assertDispatched('toast');

        expect(Banner::query()->count())->toBe(0)
            ->and(DB::table('media')->where('model_type', 'banner')->count())->toBe(0);
    });
});

describe('form', function () {
    it('creates a home banner with both images, alts and Tashkent dates stored in UTC', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->set('form.title.uz', 'Servis markazi')
            ->set('form.title.ru', 'Сервисный центр')
            ->set('form.text.uz', 'Taʼmirlash va sozlash.')
            ->set('form.button_label.uz', 'Batafsil')
            ->set('form.link', 'https://example.com/service?ref=poson')
            ->set('form.starts_at', '2030-05-10T14:30')
            ->set('form.ends_at', '2030-06-10T09:00')
            ->set('form.desktop', bannerDesktopUpload())
            ->set('form.desktop_alt.uz', 'Servis markazi')
            ->set('form.mobile', UploadedFile::fake()->image('mobile.png', 960, 720))
            ->set('form.mobile_alt.ru', 'Сервис')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $banner = Banner::query()->sole();

        expect($banner->placement)->toBe(BannerPlacement::HomeHero)
            ->and($banner->category_id)->toBeNull()
            ->and($banner->link)->toBe('https://example.com/service?ref=poson')
            ->and($banner->starts_at->utc()->format('Y-m-d H:i'))->toBe('2030-05-10 09:30')
            ->and($banner->ends_at->utc()->format('Y-m-d H:i'))->toBe('2030-06-10 04:00')
            ->and($banner->state())->toBe(BannerState::Scheduled)
            ->and($banner->sort)->toBe(1)
            ->and($banner->imageUrl('large'))->not->toBeNull()
            ->and($banner->imageUrl('mobile'))->not->toBeNull()
            ->and($banner->imageAlts(Banner::DESKTOP)['uz'])->toBe('Servis markazi')
            ->and($banner->imageAlts(Banner::MOBILE)['ru'])->toBe('Сервис');

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['banner' => $banner])
            ->assertSet('form.starts_at', '2030-05-10T14:30')
            ->assertSet('form.ends_at', '2030-06-10T09:00')
            ->assertSet('form.desktop_alt.uz', 'Servis markazi');
    });

    it('requires a category for the category placement and drops it for the home slider', function () {
        $category = Category::factory()->create();

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Skanerlar')
            ->set('form.placement', 'category')
            ->set('form.desktop', bannerDesktopUpload())
            ->call('save')
            ->assertHasErrors(['form.category_id'])
            ->assertSeeHtml('data-banner-category')
            ->set('form.category_id', $category->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(Banner::query()->sole()->category_id)->toBe($category->id);

        $banner = Banner::query()->sole();

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['banner' => $banner])
            ->set('form.placement', 'home_hero')
            ->call('save')
            ->assertHasNoErrors();

        expect($banner->fresh()->category_id)->toBeNull()
            ->and($banner->fresh()->placement)->toBe(BannerPlacement::HomeHero);
    });

    it('accepts internal paths (stored without a locale prefix) and https links and refuses the rest', function (string $link, ?string $stored) {
        $component = Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Banner')
            ->set('form.link', $link)
            ->set('form.desktop', bannerDesktopUpload())
            ->call('save');

        if ($stored === null) {
            $component->assertHasErrors(['form.link']);

            expect(Banner::query()->count())->toBe(0);

            return;
        }

        $component->assertHasNoErrors();

        expect(Banner::query()->sole()->link)->toBe($stored);
    })->with([
        'internal path' => ['/catalog/scanners?brand=zebra', '/catalog/scanners?brand=zebra'],
        'ru prefix stripped' => ['/ru/catalog/scanners', '/catalog/scanners'],
        'uz prefix with query' => ['  /uz/catalog/scanners?brand=zebra ', '/catalog/scanners?brand=zebra'],
        'bare en prefix' => ['/en', '/'],
        'prefix with query only' => ['/ru?x=1', '/?x=1'],
        'not a locale' => ['/uzbekistan/delivery', '/uzbekistan/delivery'],
        'root' => ['/', '/'],
        'https' => ['https://zebra.com/uz', 'https://zebra.com/uz'],
        'admin' => ['/admin/banners', null],
        'admin behind a locale' => ['/ru/admin', null],
        'livewire' => ['/livewire/update', null],
        'protocol-relative behind a locale' => ['/ru//evil.example.com', null],
        'protocol-relative' => ['//evil.example.com', null],
        'http' => ['http://example.com', null],
        'javascript' => ['javascript:alert(1)', null],
        'relative' => ['catalog/scanners', null],
        'backslash' => ['/\\evil.example.com', null],
        'whitespace' => ['/uz/cat alog', null],
        'dot segments to another host' => ['/ru/..//evil.example.com/', null],
        'dot segment into admin' => ['/../admin', null],
        'encoded dot segment into admin' => ['/%2e%2e/admin', null],
        'mixed-case encoded dot segment' => ['/catalog/%2E./admin', null],
        'single dot segment' => ['/catalog/./scanners', null],
        'encoded admin' => ['/%61dmin', null],
        'dots inside a name' => ['/page/v1..2', '/page/v1..2'],
    ]);

    it('validates the title, lengths, banned wording, dates order and the images', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', '')
            ->set('form.title.en', str_repeat('a', Banner::TITLE_MAX + 1))
            ->set('form.text.ru', 'Скидки до 30% на сканеры')
            ->set('form.button_label.uz', 'Arzon narxlar')
            ->set('form.desktop_alt.en', 'Cheapest printers')
            ->set('form.starts_at', '2030-05-10T14:30')
            ->set('form.ends_at', '2030-05-10T14:00')
            ->call('save')
            ->assertHasErrors([
                'form.title.uz', 'form.title.en', 'form.text.ru', 'form.button_label.uz',
                'form.desktop_alt.en', 'form.ends_at', 'form.desktop' => 'required',
            ]);

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Banner')
            ->set('form.desktop', bannerDesktopUpload(800, 300))
            ->assertHasErrors(['form.desktop' => 'dimensions'])
            ->set('form.desktop', UploadedFile::fake()->create('banner.pdf', 10, 'application/pdf'))
            ->assertHasErrors(['form.desktop']);

        expect(Banner::query()->count())->toBe(0);
    });

    it('keeps the saved desktop image and removes the mobile one on request', function () {
        $banner = Banner::factory()->withImages(mobile: true)->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['banner' => $banner])
            ->set('form.title.uz', 'Yangilangan')
            ->call('removeMobile')
            ->call('save')
            ->assertHasNoErrors();

        $banner = $banner->fresh();

        expect($banner->getTranslation('title', 'uz'))->toBe('Yangilangan')
            ->and($banner->bannerMedia(Banner::DESKTOP))->not->toBeNull()
            ->and($banner->bannerMedia(Banner::MOBILE))->toBeNull();
    });

    it('deletes from the form', function () {
        $banner = Banner::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['banner' => $banner])
            ->call('delete')
            ->assertRedirect(route('admin.banners.index'));

        expect(Banner::query()->count())->toBe(0);
    });
});

describe('SaveBanner', function () {
    it('re-checks the brand rule, link, category, dates and the desktop image whatever the caller', function (array $overrides, string $errorKey) {
        $save = app(SaveBanner::class);

        expect(fn () => $save->handle(null, bannerData($overrides), ['desktop' => bannerDesktopUpload()]))
            ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($errorKey));

        expect(Banner::query()->count())->toBe(0);
    })->with([
        'banned title' => [['title' => ['uz' => 'Chegirmalar haftaligi']], 'title.uz'],
        'banned text' => [['text' => ['en' => 'Big sale on printers']], 'text.en'],
        'long text' => [['text' => ['ru' => str_repeat('я', Banner::TEXT_MAX + 1)]], 'text.ru'],
        'missing uz title' => [['title' => ['ru' => 'Баннер']], 'title.uz'],
        'bad link' => [['link' => '//evil.example.com'], 'link'],
        'no category' => [['placement' => 'category'], 'category_id'],
        'unknown category' => [['placement' => 'category', 'category_id' => 999999], 'category_id'],
        'ends before start' => [['starts_at' => now()->addDay(), 'ends_at' => now()], 'ends_at'],
    ]);

    it('refuses a banner without a desktop image and rolls the save back', function () {
        expect(fn () => app(SaveBanner::class)->handle(null, bannerData()))
            ->toThrow(ValidationException::class);

        expect(Banner::query()->count())->toBe(0);
    });

    it('refuses a banned alt text', function () {
        expect(fn () => app(SaveBanner::class)->handle(null, bannerData(), ['desktop' => bannerDesktopUpload(), 'desktop_alt' => ['ru' => 'Дешёвые сканеры']]))
            ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('desktop_alt.ru'));

        expect(Banner::query()->count())->toBe(0);
    });

    it('puts a new banner, or one moved to another list, at the end of that list', function () {
        $category = Category::factory()->create();
        Banner::factory()->create(['sort' => 5]);
        Banner::factory()->category($category)->create(['sort' => 2]);

        $home = app(SaveBanner::class)->handle(null, bannerData(), ['desktop' => bannerDesktopUpload()]);
        expect($home->sort)->toBe(6);

        $moved = app(SaveBanner::class)->handle($home, bannerData(['placement' => 'category', 'category_id' => $category->id]));
        expect($moved->sort)->toBe(3);
    });
});
