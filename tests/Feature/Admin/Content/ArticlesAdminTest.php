<?php

use App\Enums\NewsKind;
use App\Enums\Role;
use App\Livewire\Admin\Articles\Form;
use App\Livewire\Admin\Articles\Index;
use App\Livewire\Admin\News\Form as NewsForm;
use App\Livewire\Admin\News\Index as NewsIndex;
use App\Models\NewsPost;
use Livewire\Livewire;

describe('access', function () {
    it('opens the articles screens for admin and content manager', function (Role $role) {
        $article = NewsPost::factory()->article()->create();

        $this->actingAs(staffUser($role));
        $this->get(route('admin.articles.index'))->assertOk()->assertSeeLivewire(Index::class)->assertSee((string) $article->title);
        $this->get(route('admin.articles.create'))->assertOk();
        $this->get(route('admin.articles.edit', $article))->assertOk();
    })->with(catalogWriterRoles());

    it('forbids the articles screens to sales managers and service engineers', function (Role $role) {
        $article = NewsPost::factory()->article()->create();
        $user = staffUser($role);

        $this->actingAs($user);
        $this->get(route('admin.articles.index'))->assertForbidden();
        $this->get(route('admin.articles.create'))->assertForbidden();
        $this->get(route('admin.articles.edit', $article))->assertForbidden();

        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
    })->with(catalogViewOnlyRoles());

    it('shows the Articles menu item next to News for content roles only', function () {
        $this->actingAs(staffUser(Role::ContentManager))
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['href="'.route('admin.news.index').'"', 'href="'.route('admin.articles.index').'"'], false);

        $this->actingAs(staffUser(Role::SalesManager))
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('href="'.route('admin.articles.index').'"', false);
    });
});

describe('kind separation', function () {
    it('lists only its own kind on each screen', function () {
        NewsPost::factory()->create(['title' => ['uz' => 'Kompaniya yangiligi']]);
        NewsPost::factory()->article()->create(['title' => ['uz' => 'Skaner qoʻllanmasi']]);
        $user = staffUser(Role::ContentManager);

        Livewire::actingAs($user)->test(Index::class)->assertSee('Skaner qoʻllanmasi')->assertDontSee('Kompaniya yangiligi')->assertSee('/articles/', false);
        Livewire::actingAs($user)->test(NewsIndex::class)->assertSee('Kompaniya yangiligi')->assertDontSee('Skaner qoʻllanmasi');
    });

    it('returns 404 for a post of the other kind on each edit screen', function () {
        $news = NewsPost::factory()->create();
        $article = NewsPost::factory()->article()->create();

        $this->actingAs(staffUser());
        $this->get(route('admin.articles.edit', $news))->assertNotFound();
        $this->get(route('admin.news.edit', $article))->assertNotFound();
    });

    it('does not delete a news post from the articles list', function () {
        $news = NewsPost::factory()->create();

        Livewire::actingAs(staffUser())->test(Index::class)->call('delete', $news->id)->assertNotFound();

        expect($news->fresh())->not->toBeNull();
    });

    it('creates articles from the articles form and news from the news form', function () {
        $user = staffUser(Role::ContentManager);

        Livewire::actingAs($user)->test(Form::class)
            ->set('form.title.uz', 'Yorliq printerini sozlash')
            ->set('form.body.uz', '<p>Matn.</p>')
            ->set('form.status', 'draft')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.articles.edit', NewsPost::query()->where('slug', 'yorliq-printerini-sozlash')->sole()));

        Livewire::actingAs($user)->test(NewsForm::class)
            ->set('form.title.uz', 'Yangi ofis')
            ->set('form.body.uz', '<p>Matn.</p>')
            ->set('form.status', 'draft')
            ->call('save')
            ->assertHasNoErrors();

        expect(NewsPost::query()->where('slug', 'yorliq-printerini-sozlash')->sole()->kind)->toBe(NewsKind::Article)
            ->and(NewsPost::query()->where('slug', 'yangi-ofis')->sole()->kind)->toBe(NewsKind::News);
    });

    it('keeps the kind when an article is edited and keeps slugs unique across kinds', function () {
        NewsPost::factory()->create(['slug' => 'shared-slug']);
        $article = NewsPost::factory()->article()->create(['slug' => 'guide']);

        Livewire::actingAs(staffUser())->test(Form::class, ['newsPost' => $article])
            ->set('form.slug', 'shared-slug')
            ->call('save')
            ->assertHasErrors('form.slug');

        Livewire::actingAs(staffUser())->test(Form::class, ['newsPost' => $article])
            ->set('form.title.uz', 'Yangi sarlavha')
            ->call('save')
            ->assertHasNoErrors();

        expect($article->fresh()->kind)->toBe(NewsKind::Article)
            ->and($article->fresh()->getTranslation('title', 'uz'))->toBe('Yangi sarlavha');
    });

    it('links the public article URL from the form', function () {
        $article = NewsPost::factory()->article()->create(['slug' => 'public-guide']);

        Livewire::actingAs(staffUser())->test(Form::class, ['newsPost' => $article])
            ->assertSeeHtml('href="'.url('/uz/articles/public-guide').'"');
    });
});
