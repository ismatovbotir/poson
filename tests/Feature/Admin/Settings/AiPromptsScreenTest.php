<?php

use App\Actions\Ai\ResetAiPromptOverride;
use App\Actions\Ai\SaveAiPromptOverride;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\AiPrompts\Edit;
use App\Livewire\Admin\Settings\AiPrompts\Index;
use App\Models\AiPromptOverride;
use App\Models\SettingsAudit;
use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;
use App\Support\Ai\PromptOverrides;
use App\Support\Ai\PromptTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(fn () => app()->setLocale('en'));

describe('screens', function () {
    it('opens the index and edit screens for the admin only', function (Role $role, int $status) {
        $this->actingAs(staffUser($role));

        $this->get(route('admin.settings.ai-prompts.index'))->assertStatus($status);
        $this->get(route('admin.settings.ai-prompts.edit', 'product.meta_title'))->assertStatus($status);
    })->with([
        'admin' => [Role::Admin, 200],
        'content manager' => [Role::ContentManager, 403],
        'sales manager' => [Role::SalesManager, 403],
        'service engineer' => [Role::ServiceEngineer, 403],
    ]);

    it('forbids the Livewire components to other roles', function (Role $role) {
        $this->actingAs(staffUser($role));

        Livewire::test(Index::class)->assertForbidden();
        Livewire::test(Edit::class, ['fieldKey' => 'product.meta_title'])->assertForbidden();
    })->with(['content manager' => [Role::ContentManager], 'sales manager' => [Role::SalesManager]]);

    it('returns 404 for an unknown or retired field key', function (string $key) {
        $this->actingAs(staffUser())->get('/admin/settings/ai-prompts/'.$key)->assertNotFound();
    })->with(['retired' => ['product.name'], 'unknown' => ['foo.bar'], 'malformed' => ['Product.Meta']]);

    it('lists the fields grouped, with Default and Customized badges', function () {
        $override = AiPromptOverride::factory()->forField('brand.meta_title')->create(['prompt' => 'SEO title for {name}, ending with Poson.']);
        AiPromptOverride::factory()->forField('product.name')->create(['prompt' => 'Retired {name}.']);

        $html = $this->actingAs(staffUser())->get(route('admin.settings.ai-prompts.index'))->assertOk()->getContent();

        foreach (FieldRegistry::GROUPS as $group) {
            expect($html)->toContain('data-prompt-group="'.$group.'"');
        }

        expect($html)->toContain('data-prompt-field="banner.text"')
            ->toContain('data-prompt-field="product.image_alt"')
            ->not->toContain('data-prompt-field="product.name"')
            ->toContain(e($override->updatedBy->name))
            ->and(substr_count($html, 'data-prompt-customized="'))->toBe(1)
            ->and(substr_count($html, 'data-prompt-default="'))->toBe(count(FieldRegistry::fields()) - 1)
            ->and($html)->toContain('href="'.route('admin.settings.ai-prompts.edit', 'brand.meta_title').'"');
    });

    it('reads the overrides with one query, whatever the number of fields', function () {
        AiPromptOverride::factory()->forField('brand.meta_title')->create();
        AiPromptOverride::factory()->forField('faq.answer')->create(['prompt' => 'Answer {question} briefly.']);
        $this->actingAs(staffUser());
        Livewire::test(Index::class)->assertOk();

        $queries = countQueries(fn () => Livewire::test(Index::class)->assertOk());

        AiPromptOverride::factory()->forField('banner.text')->create(['prompt' => 'Subtitle for {name} in {category}.']);

        expect(countQueries(fn () => Livewire::test(Index::class)->assertOk()))->toBe($queries);
    });

    it('shows the edit screen with the defaults per locale, chips, preview and the fixed-rules note', function () {
        $html = $this->actingAs(staffUser())->get(route('admin.settings.ai-prompts.edit', 'product.meta_title'))->assertOk()->getContent();

        expect($html)->toContain('data-prompt-default-text="uz"')
            ->toContain('data-prompt-default-text="ru"')
            ->toContain('data-prompt-default-text="en"')
            ->toContain(e(__('ai.hints.product.meta_title', [], 'ru')))
            ->toContain('data-placeholder-chip="name"')
            ->toContain('data-placeholder-chip="brand"')
            ->toContain('data-placeholder-chip="category"')
            ->not->toContain('data-placeholder-chip="specs"')
            ->toContain('data-prompt-preview')
            ->toContain('data-prompt-fixed-rules')
            ->toContain('/'.PromptTemplate::MAX_LENGTH)
            ->toContain(e(__('admin.ai_prompts.length_hint', ['min' => PromptTemplate::MIN_LENGTH, 'max' => PromptTemplate::MAX_LENGTH])))
            ->not->toContain('data-prompt-reset');
    });

    it('shows warnings and the unknown-placeholder error while typing', function () {
        $this->actingAs(staffUser());

        Livewire::test(Edit::class, ['fieldKey' => 'product.meta_title'])
            ->assertSet('prompt', __('ai.hints.product.meta_title'))
            ->set('prompt', 'Title for {name}, cheapest in town, with {specs}.')
            ->assertSeeHtml('data-prompt-unknown')
            ->assertSee('{specs}')
            ->assertSeeHtml('data-prompt-missing')
            ->assertSee('{brand}, {category}')
            ->assertSeeHtml('data-prompt-brand')
            ->set('prompt', 'Title for {name} by {brand} in {category}.')
            ->assertDontSeeHtml('data-prompt-unknown')
            ->assertDontSeeHtml('data-prompt-missing')
            ->assertDontSeeHtml('data-prompt-brand')
            ->assertSee(PromptTemplate::preview(FieldRegistry::get('product.meta_title'), 'Title for {name} by {brand} in {category}.'));
    });

    it('saves and resets through the screen', function () {
        $this->actingAs($admin = staffUser());

        $component = Livewire::test(Edit::class, ['fieldKey' => 'brand.meta_title'])
            ->set('prompt', "  SEO title for {name},\r\nending with Poson.  ")
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertSet('prompt', "SEO title for {name},\nending with Poson.")
            ->assertSeeHtml('data-prompt-customized')
            ->assertSeeHtml('data-prompt-reset');

        expect(AiPromptOverride::query()->sole())
            ->field_key->toBe('brand.meta_title')
            ->prompt->toBe("SEO title for {name},\nending with Poson.")
            ->updated_by->toBe($admin->id)
            ->and(PromptOverrides::for('brand.meta_title'))->toBe("SEO title for {name},\nending with Poson.");

        $component->call('resetToDefault')
            ->assertSet('prompt', __('ai.hints.brand.meta_title'))
            ->assertDontSeeHtml('data-prompt-reset');

        expect(AiPromptOverride::query()->count())->toBe(0)
            ->and(PromptOverrides::for('brand.meta_title'))->toBeNull();
    });

    it('shows a save error on the prompt and keeps the row unchanged', function () {
        $this->actingAs(staffUser());

        Livewire::test(Edit::class, ['fieldKey' => 'faq.answer'])
            ->set('prompt', 'Answer about {brand}.')
            ->call('save')
            ->assertHasErrors('prompt');

        expect(AiPromptOverride::query()->count())->toBe(0);
    });

    it('re-checks the permission on every request', function () {
        $this->actingAs($admin = staffUser());
        $component = Livewire::test(Edit::class, ['fieldKey' => 'brand.meta_title']);

        $admin->syncRoles([Role::ContentManager->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->unsetRelation('roles')->unsetRelation('permissions');

        $component->set('prompt', 'SEO title for {name} by Poson.')->assertForbidden();

        expect(AiPromptOverride::query()->count())->toBe(0);
    });
});

describe('actions', function () {
    it('creates and updates an override with an audit row that never holds the text', function () {
        $admin = staffUser();

        $override = app(SaveAiPromptOverride::class)->handle('banner.text', 'Subtitle for {name} on {category}.', $admin, '10.0.0.1');
        app(SaveAiPromptOverride::class)->handle('banner.text', 'Subtitle for {name} on {category}.', $admin, '10.0.0.1');
        app(SaveAiPromptOverride::class)->handle('banner.text', 'New subtitle for {name} on {category}.', $admin, '10.0.0.1');

        $audits = SettingsAudit::query()->orderBy('id')->get();

        expect($override->fresh()->prompt)->toBe('New subtitle for {name} on {category}.')
            ->and(AiPromptOverride::query()->count())->toBe(1)
            ->and($audits)->toHaveCount(2)
            ->and($audits->pluck('action')->all())->toBe([AuditAction::Updated, AuditAction::Updated])
            ->and($audits->first())
            ->area->toBe(SettingsArea::AiPrompts)
            ->key->toBe('banner.text')
            ->user_id->toBe($admin->id)
            ->ip->toBe('10.0.0.1')
            ->and(json_encode($audits->toArray()))->not->toContain('Subtitle');
    });

    it('rejects unknown keys, unknown placeholders and bad lengths', function (string $key, string $prompt) {
        expect(fn () => app(SaveAiPromptOverride::class)->handle($key, $prompt, staffUser(), null))->toThrow(ValidationException::class)
            ->and(AiPromptOverride::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    })->with([
        'retired key' => ['product.name', 'Write the name of {name}.'],
        'unknown key' => ['foo.bar', 'Write something for {name}.'],
        'specs placeholder' => ['product.meta_title', 'Title for {name} using {specs}.'],
        'placeholder of another field' => ['brand.meta_title', 'Title for {name} in {category}.'],
        'stray brace' => ['brand.meta_title', 'Title for {name} with a { brace.'],
        'too short' => ['brand.meta_title', '{name}'],
        'too long' => ['brand.meta_title', '{name} '.str_repeat('a', PromptTemplate::MAX_LENGTH)],
        'blank' => ['brand.meta_title', "  \n "],
    ]);

    it('deletes the override when the text equals a default of any locale', function (string $locale) {
        $admin = staffUser();
        AiPromptOverride::factory()->forField('product.meta_title')->create();

        $result = app(SaveAiPromptOverride::class)->handle('product.meta_title', '  '.__('ai.hints.product.meta_title', [], $locale)."\n", $admin, null);

        expect($result)->toBeNull()
            ->and(AiPromptOverride::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->sole()->action)->toBe(AuditAction::Cleared);

        // Nothing to delete: no audit row.
        app(SaveAiPromptOverride::class)->handle('product.meta_title', __('ai.hints.product.meta_title', [], $locale), $admin, null);

        expect(SettingsAudit::query()->count())->toBe(1);
    })->with(['uz', 'ru', 'en']);

    it('resets an override with a Cleared audit row, and is a no-op without one', function () {
        $admin = staffUser();
        AiPromptOverride::factory()->forField('faq.answer')->create(['prompt' => 'Answer {question} for {name}.']);

        expect(app(ResetAiPromptOverride::class)->handle('faq.answer', $admin, '10.0.0.2'))->toBeTrue()
            ->and(app(ResetAiPromptOverride::class)->handle('faq.answer', $admin, '10.0.0.2'))->toBeFalse()
            ->and(AiPromptOverride::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->sole())
            ->action->toBe(AuditAction::Cleared)
            ->key->toBe('faq.answer')
            ->area->toBe(SettingsArea::AiPrompts);
    });

    it('authorizes the actions themselves', function () {
        $user = staffUser(Role::ContentManager);

        expect(fn () => app(SaveAiPromptOverride::class)->handle('brand.meta_title', 'SEO title for {name}.', $user, null))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ResetAiPromptOverride::class)->handle('brand.meta_title', $user, null))->toThrow(AuthorizationException::class)
            ->and(AiPromptOverride::query()->count())->toBe(0);
    });

    it('uses a saved override as the filled-in hint of the assistant', function () {
        app(SaveAiPromptOverride::class)->handle('brand.meta_title', 'Brand title for {name} ending with Poson.', staffUser(), null);

        expect(FieldRegistry::get('brand.meta_title')->hintFor(new AiContext(name: 'Zebra')))->toBe('Brand title for Zebra ending with Poson.');
    });
});
