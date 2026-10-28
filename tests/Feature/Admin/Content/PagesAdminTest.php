<?php

use App\Actions\Ai\GenerateFieldText;
use App\Actions\Pages\DeletePage;
use App\Actions\Pages\SavePage;
use App\Enums\PageTemplate;
use App\Enums\Role;
use App\Livewire\Admin\Ai\Assistant;
use App\Livewire\Admin\Faq\FaqItems;
use App\Livewire\Admin\Pages\Form;
use App\Livewire\Admin\Pages\Index;
use App\Models\AiRequest;
use App\Models\FaqItem;
use App\Models\Page;
use App\Support\Ai\AiContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

describe('access', function () {
    it('opens the pages screens for admin and content manager', function (Role $role) {
        $page = Page::factory()->create();

        $this->actingAs(staffUser($role));
        $this->get(route('admin.pages.index'))->assertOk()->assertSeeLivewire(Index::class)->assertSee((string) $page->title);
        $this->get(route('admin.pages.create'))->assertOk();
        $this->get(route('admin.pages.edit', $page))->assertOk();
    })->with(catalogWriterRoles());

    it('forbids the pages screens to sales managers and service engineers', function (Role $role) {
        $page = Page::factory()->create();

        $this->actingAs(staffUser($role));
        $this->get(route('admin.pages.index'))->assertForbidden();
        $this->get(route('admin.pages.create'))->assertForbidden();
        $this->get(route('admin.pages.edit', $page))->assertForbidden();
    })->with(catalogViewOnlyRoles());

    it('applies the page policy per role', function (Role $role, bool $manage) {
        $user = staffUser($role);
        $custom = Page::factory()->create();
        $system = Page::factory()->system('privacy')->create();

        expect($user->can('viewAny', Page::class))->toBe($manage)
            ->and($user->can('create', Page::class))->toBe($manage)
            ->and($user->can('update', $custom))->toBe($manage)
            ->and($user->can('delete', $custom))->toBe($manage)
            ->and($user->can('news.update'))->toBe($manage)
            ->and($user->can('banners.delete'))->toBe($manage);

        if ($role !== Role::Admin) {
            expect($user->can('delete', $system))->toBeFalse();
        }
    })->with([
        'admin' => [Role::Admin, true],
        'content manager' => [Role::ContentManager, true],
        'sales manager' => [Role::SalesManager, false],
        'service engineer' => [Role::ServiceEngineer, false],
    ]);
});

describe('index', function () {
    it('shows the review, system and status badges and filters pages needing review', function () {
        Page::factory()->system('privacy')->needsReview()->create(['title' => ['uz' => 'Maxfiylik']]);
        Page::factory()->create(['title' => ['uz' => 'Kafolat sahifasi']]);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->assertSeeHtml('data-needs-review')
            ->assertSee(__('admin.pages.system'))
            ->set('status', 'review')
            ->assertSee('Maxfiylik')
            ->assertDontSee('Kafolat sahifasi')
            ->set('status', '')
            ->set('search', 'kafolat')
            ->assertSee('Kafolat sahifasi')
            ->assertDontSee('Maxfiylik');
    });

    it('never offers or performs the delete of a system page, even for the admin', function () {
        $system = Page::factory()->system('about')->create();
        $custom = Page::factory()->create(['slug' => 'careers']);

        Livewire::actingAs(staffUser())
            ->test(Index::class)
            ->assertSeeHtml('data-delete-page="careers"')
            ->assertDontSeeHtml('data-delete-page="about"')
            ->call('delete', $system->id)
            ->assertDispatched('toast', type: 'error');

        expect($system->fresh())->not->toBeNull();

        expect(fn () => app(DeletePage::class)->handle($system))->toThrow(ValidationException::class);
    });

    it('forbids the content manager to delete a system page', function () {
        $system = Page::factory()->system('contacts')->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->call('delete', $system->id)
            ->assertForbidden();

        expect($system->fresh())->not->toBeNull();
    });

    it('deletes a custom page with its FAQ', function () {
        $page = Page::factory()->create();
        FaqItem::factory()->owner($page)->count(2)->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->call('delete', $page->id)
            ->assertDispatched('toast');

        expect(Page::query()->count())->toBe(0)
            ->and(FaqItem::query()->count())->toBe(0);
    });
});

describe('form', function () {
    it('creates a custom page with an auto slug and sanitized body', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->set('form.title.uz', 'Boʻsh ish oʻrinlari')
            ->set('form.title.en', 'Careers')
            ->set('form.body.uz', '<p onclick="x()">Matn <a href="https://example.com">havola</a> <a href="/uz/page/about">biz</a></p><script>alert(1)</script>')
            ->set('form.template', 'default')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $page = Page::query()->sole();

        expect($page->slug)->toBe('careers')
            ->and($page->is_system)->toBeFalse()
            ->and($page->template)->toBe(PageTemplate::Default)
            ->and($page->getTranslation('body', 'uz'))->toBe('<p>Matn <a href="https://example.com" rel="nofollow">havola</a> <a href="/uz/page/about">biz</a></p>');
    });

    it('rejects banned wording', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Eng arzon narxlar')
            ->set('form.meta_description.ru', 'Скидки каждый день')
            ->call('save')
            ->assertHasErrors(['form.title.uz', 'form.meta_description.ru']);

        expect(Page::query()->count())->toBe(0);
    });

    it('generates the slug from the title, never a reserved system or taken one, and ignores a client slug', function () {
        Page::factory()->create(['slug' => 'careers']);

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->assertDontSeeHtml('wire:model="form.slug"')
            ->assertSeeHtml('data-slug-preview')
            ->set('form.title.uz', 'Biz haqimizda')
            ->set('form.title.en', 'About')
            ->assertSeeHtml('data-slug-value>about-2</span>')
            ->set('form.slug', 'privacy')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Ishlar')
            ->set('form.title.en', 'Careers')
            ->call('save')
            ->assertHasNoErrors();

        expect(Page::query()->where('slug', 'about')->exists())->toBeFalse()
            ->and(Page::query()->where('slug', 'privacy')->exists())->toBeFalse()
            ->and(Page::query()->pluck('slug')->sort()->values()->all())->toBe(['about-2', 'careers', 'careers-2']);
    });

    it('previews the suffixed slug when the title matches a system slug', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Biz haqimizda')
            ->set('form.title.en', 'About')
            ->assertSeeHtml('data-slug-value>about-2</span>');
    });

    it('regenerates the slug once when a page with the same title is created concurrently', function () {
        $clashed = false;

        Page::creating(function (Page $page) use (&$clashed): void {
            if (! $clashed) {
                $clashed = true;
                Page::factory()->create(['slug' => $page->slug]);
            }
        });

        $page = app(SavePage::class)->handle(null, [
            'title' => ['uz' => 'Hamkorlar', 'en' => 'Partners'],
            'body' => [],
            'meta_title' => [],
            'meta_description' => [],
            'template' => 'default',
            'sort' => null,
            'is_active' => true,
            'needs_review' => false,
        ]);

        expect($page->slug)->toBe('partners-2');
    });

    it('keeps the slug of a custom page when it is renamed or the slug is tampered with', function () {
        $page = Page::factory()->create(['slug' => 'delivery-info']);

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['page' => $page])
            ->assertSeeHtml('data-slug-locked')
            ->set('form.title.en', 'Shipping and pickup')
            ->set('form.slug', 'forged')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.slug', 'delivery-info');

        expect($page->fresh()->slug)->toBe('delivery-info');
    });

    it('rejects banned wording in the body through the action', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.title.uz', 'Sahifa')
            ->set('form.body.ru', '<p>Самые <strong>дешёвые</strong> принтеры</p>')
            ->call('save')
            ->assertHasErrors(['form.body.ru']);

        expect(Page::query()->count())->toBe(0);
    });

    it('keeps the slug and template of a system page read-only', function () {
        $page = Page::factory()->system('privacy')->needsReview()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['page' => $page])
            ->assertSeeHtml('data-slug-locked')
            ->assertSeeHtml('data-template-locked')
            ->assertSeeHtml('data-needs-review')
            ->set('form.slug', 'privacy-new')
            ->set('form.template', 'default')
            ->set('form.title.ru', 'Политика конфиденциальности')
            ->set('form.needs_review', false)
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $page->refresh();

        expect($page->slug)->toBe('privacy')
            ->and($page->template)->toBe(PageTemplate::Legal)
            ->and($page->getTranslation('title', 'ru'))->toBe('Политика конфиденциальности')
            ->and($page->needs_review)->toBeFalse()
            ->and($page->is_active)->toBeFalse();
    });

    it('stores an optional cover image privately with WebP conversions and alt per locale', function () {
        Storage::fake('public');
        Storage::fake('local');
        $page = Page::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['page' => $page])
            ->set('form.image', UploadedFile::fake()->image('cover.jpg', 1600, 900))
            ->set('form.image_alt.uz', 'Poson ofisi')
            ->call('save')
            ->assertHasNoErrors();

        $media = $page->fresh()->getFirstMedia('cover');

        expect($media)->not->toBeNull()
            ->and($media->disk)->toBe('local')
            ->and($page->fresh()->imageUrl('large'))->toContain('conversions/')->toEndWith('.webp')
            ->and($page->fresh()->imageAlt('ru'))->toBe('Poson ofisi');

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['page' => $page->fresh()])
            ->call('removeImage')
            ->call('save')
            ->assertHasNoErrors();

        expect($page->fresh()->getFirstMedia('cover'))->toBeNull();
    });

    it('shows the FAQ block after the first save', function () {
        $page = Page::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->assertSee(__('admin.faq.save_first'));

        $this->actingAs(staffUser(Role::ContentManager))
            ->get(route('admin.pages.edit', $page))
            ->assertOk()
            ->assertSeeLivewire(FaqItems::class);
    });
});

describe('content date', function () {
    it('sets content_updated_at on create and only when the title or body change', function () {
        $this->freezeSecond();
        $user = staffUser(Role::ContentManager);

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('form.title.uz', 'Yetkazib berish shartlari')
            ->set('form.body.uz', '<p>Matn <a href="/uz/page/about">biz haqimizda</a>.</p>')
            ->call('save')
            ->assertHasNoErrors();

        $page = Page::query()->sole();
        $created = now()->toImmutable();
        expect($page->content_updated_at->equalTo($created))->toBeTrue();

        // SEO, active, sort and review flag: the content date stays.
        $this->travel(2)->days();
        Livewire::actingAs($user)
            ->test(Form::class, ['page' => $page])
            ->set('form.meta_title.ru', 'Доставка')
            ->set('form.meta_description.uz', 'Yetkazib berish haqida')
            ->set('form.is_active', false)
            ->set('form.needs_review', true)
            ->set('form.sort', '7')
            ->call('save')
            ->assertHasNoErrors();

        $page->refresh();
        expect($page->content_updated_at->equalTo($created))->toBeTrue()
            ->and($page->updated_at->equalTo(now()))->toBeTrue()
            ->and($page->contentUpdatedAt()->equalTo($created))->toBeTrue();

        // A body change in any locale moves it.
        $this->travel(1)->days();
        Livewire::actingAs($user)
            ->test(Form::class, ['page' => $page])
            ->set('form.body.ru', '<p>Новый текст.</p>')
            ->call('save')
            ->assertHasNoErrors();

        expect($page->refresh()->content_updated_at->equalTo(now()))->toBeTrue();

        // So does a title change.
        $this->travel(1)->days();
        Livewire::actingAs($user)
            ->test(Form::class, ['page' => $page])
            ->set('form.title.en', 'Delivery terms')
            ->call('save')
            ->assertHasNoErrors();

        expect($page->refresh()->content_updated_at->equalTo(now()))->toBeTrue();
    });

    it('ignores whitespace-only edits of the title and body', function () {
        $page = app(SavePage::class)->handle(null, [
            'title' => ['uz' => 'Kafolat', 'ru' => 'Гарантия'],
            'body' => ['uz' => '<p>Matn</p>'],
            'meta_title' => [],
            'meta_description' => [],
            'slug' => null,
            'template' => 'default',
            'sort' => null,
            'is_active' => true,
            'needs_review' => false,
        ]);
        $created = $page->content_updated_at;

        $this->travel(1)->day();
        app(SavePage::class)->handle($page->fresh(), [
            'title' => ['ru' => ' Гарантия ', 'uz' => 'Kafolat ', 'en' => ''],
            'body' => ['uz' => ' <p>Matn</p> ', 'ru' => '<p></p>'],
            'meta_title' => ['uz' => 'Kafolat — Poson'],
            'meta_description' => [],
            'slug' => $page->slug,
            'template' => 'default',
            'sort' => 3,
            'is_active' => true,
            'needs_review' => false,
        ]);

        expect($page->refresh()->content_updated_at->equalTo($created))->toBeTrue();
    });

    it('falls back to updated_at, then created_at, when the content date is empty', function () {
        $page = Page::factory()->create(['content_updated_at' => null]);

        expect($page->contentUpdatedAt()->equalTo($page->updated_at))->toBeTrue();

        $page->updated_at = null;

        expect($page->contentUpdatedAt()->equalTo($page->created_at))->toBeTrue();
    });
});

describe('page FAQ', function () {
    it('lets the content manager add FAQ items to a page', function () {
        $page = Page::factory()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(FaqItems::class, ['page' => $page])
            ->call('create')
            ->set('form.question.uz', 'Yetkazib berish qancha vaqt oladi?')
            ->set('form.answer.uz', 'Buyurtmaga bogʻliq.')
            ->call('save')
            ->assertHasNoErrors();

        expect(FaqItem::query()->sole())
            ->faqable_type->toBe('page')
            ->faqable_id->toBe($page->id);
    });

    it('forbids the page FAQ to roles without pages access', function () {
        $page = Page::factory()->create();

        Livewire::actingAs(staffUser(Role::SalesManager))
            ->test(FaqItems::class, ['page' => $page])
            ->assertForbidden();
    });
});

describe('SI assist', function () {
    beforeEach(function () {
        enableAiAssist();
        fakeTextAssistant();
    });

    it('offers SI on every page field except the body of legal pages', function () {
        $default = Page::factory()->create();
        $legal = Page::factory()->system('privacy')->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['page' => $default])
            ->assertDontSeeHtml('data-ai-button="page.title"')
            ->assertSeeHtml('data-ai-button="page.body"')
            ->assertSeeHtml('data-ai-button="page.meta_description"');

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['page' => $legal])
            ->assertSeeHtml('data-ai-button="page.meta_title"')
            ->assertDontSeeHtml('data-ai-button="page.body"')
            ->assertSee(__('admin.pages.legal_body_hint'))
            ->call('openAi', 'form.body', 'uz')
            ->assertNotDispatched('ai-open')
            ->call('openAi', 'form.meta_title', 'uz')
            ->assertDispatched('ai-open', field: 'page.meta_title');
    });

    it('keeps the body refused for a stored legal page whose form template was switched', function () {
        $legal = Page::factory()->legal()->create();

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['page' => $legal])
            ->set('form.template', 'default')
            ->assertDontSeeHtml('data-ai-button="page.body"')
            ->call('openAi', 'form.body', 'uz')
            ->assertNotDispatched('ai-open')
            ->dispatch('ai-apply', owner: 'x', target: 'form.body', field: 'page.body', values: ['uz' => '<p>SI</p>'])
            ->assertSet('form.body.uz', $legal->getTranslation('body', 'uz'));
    });

    it('builds the page context from the stored record, never from the form state', function () {
        $legal = Page::factory()->legal()->create(['slug' => 'terms']);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class, ['page' => $legal])
            ->set('form.slug', 'other')
            ->set('form.template', 'default')
            ->call('openAi', 'form.meta_title', 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'page.meta_title'
                && $params['context']['subject_type'] === 'page'
                && $params['context']['subject_id'] === $legal->id
                && $params['context']['facts']['Page'] === 'page/terms (legal template)');
    });

    it('refuses the body of a stored legal page in the assistant and the AI action', function () {
        $user = staffUser(Role::ContentManager);
        $legal = Page::factory()->legal()->create();
        $default = Page::factory()->create();
        $forged = ['name' => 'X', 'subject_type' => 'page', 'subject_id' => $legal->id];

        // A forged ai-open event for the legal body never opens the panel.
        Livewire::actingAs($user)
            ->test(Assistant::class)
            ->call('openFor', 'form-1', 'form.body', 'page.body', 'uz', $forged, [])
            ->assertSet('open', false)
            ->assertSet('fieldKey', '');

        // The action refuses it whatever the caller.
        expect(fn () => app(GenerateFieldText::class)->handle($user, 'page.body', 'Write it.', AiContext::fromArray($forged)))
            ->toThrow(ValidationException::class, __('ai.errors.legal_body'));
        expect(AiRequest::query()->count())->toBe(0);

        // Meta title of the legal page and the body of a default page still work, with the page as subject.
        Livewire::actingAs($user)
            ->test(Assistant::class)
            ->call('openFor', 'form-1', 'form.meta_title', 'page.meta_title', 'uz', $forged, [])
            ->assertSet('open', true)
            ->assertSet('context.subject_id', $legal->id);

        $request = app(GenerateFieldText::class)->handle($user, 'page.body', 'Write it.', AiContext::fromArray(['name' => 'Y', 'subject_type' => 'page', 'subject_id' => $default->id]));

        expect($request->field_key)->toBe('page.body')
            ->and($request->subject_type)->toBe('page')
            ->and($request->subject_id)->toBe($default->id);
    });

    it('hides the body button when a custom page switches to the legal template', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->assertSeeHtml('data-ai-button="page.body"')
            ->set('form.template', 'legal')
            ->assertDontSeeHtml('data-ai-button="page.body"');
    });
});
