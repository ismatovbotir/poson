<?php

use App\Actions\Ai\ProcessAiRequest;
use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Enums\AiRequestStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\AiPromptOverride;
use App\Models\AiRequest;
use App\Services\Ai\TextTask;
use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;
use App\Support\Ai\PromptOverrides;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => app()->setLocale('en'));

it('uses the localized default when no override exists', function () {
    $field = FieldRegistry::get('product.meta_title');

    expect($field->isOverridden())->toBeFalse()
        ->and($field->template())->toBe(__('ai.hints.product.meta_title'))
        ->and($field->template('ru'))->toBe(__('ai.hints.product.meta_title', [], 'ru'))
        ->and($field->hintFor(new AiContext(name: 'Zebra ZD421')))->toStartWith('Write an SEO title for the product page of Zebra ZD421');
});

it('prefers the override in every locale and fills it', function () {
    AiPromptOverride::factory()->forField('product.meta_title')->create(['prompt' => 'Title for {name} by {brand}.']);
    $field = FieldRegistry::get('product.meta_title');

    expect($field->isOverridden())->toBeTrue()
        ->and($field->template())->toBe('Title for {name} by {brand}.')
        ->and($field->template('ru'))->toBe('Title for {name} by {brand}.')
        ->and($field->defaultTemplate())->toBe(__('ai.hints.product.meta_title'))
        ->and($field->hintFor(new AiContext(name: 'Zebra ZD421', brand: 'Zebra')))->toBe('Title for Zebra ZD421 by Zebra.');

    app()->setLocale('uz');

    expect($field->hintFor(new AiContext(name: 'ZD421')))->toBe('Title for ZD421 by —.');
});

it('caches the overrides and flushes them on save and delete', function () {
    expect(PromptOverrides::all())->toBe([])
        ->and(Cache::has(PromptOverrides::CACHE_KEY))->toBeTrue();

    $override = AiPromptOverride::factory()->forField('faq.answer')->create(['prompt' => 'Answer {question}.']);

    expect(Cache::has(PromptOverrides::CACHE_KEY))->toBeFalse()
        ->and(PromptOverrides::for('faq.answer'))->toBe('Answer {question}.');

    $override->update(['prompt' => 'Answer {question} for {name}.']);

    expect(PromptOverrides::for('faq.answer'))->toBe('Answer {question} for {name}.');

    $override->delete();

    expect(PromptOverrides::for('faq.answer'))->toBeNull()
        ->and(FieldRegistry::get('faq.answer')->isOverridden())->toBeFalse();
});

it('ignores overrides of unregistered keys and blank texts', function () {
    AiPromptOverride::factory()->forField('product.name')->create(['prompt' => 'Old name hint {name}.']);
    AiPromptOverride::factory()->forField('brand.description')->create(['prompt' => '   ']);

    expect(PromptOverrides::all())->toBe([])
        ->and(FieldRegistry::get('brand.description')->template())->toBe(__('ai.hints.brand.description'));
});

it('keeps the editor through updatedBy', function () {
    $override = AiPromptOverride::factory()->create();

    expect($override->updatedBy)->not->toBeNull()
        ->and($override->updated_by)->toBe($override->updatedBy->id);

    $override->updatedBy->delete();

    expect($override->fresh()->updated_by)->toBeNull();
});

it('fails a queued request of a retired field gracefully', function () {
    enableAiAssist();
    $fake = fakeTextAssistant();
    $request = AiRequest::factory()->pending()->create(['field_key' => 'product.name']);

    $result = app(ProcessAiRequest::class)->handle($request->id, new TextTask(AiFeature::TextGenerate, 'product.name', 'Write the name.', new AiContext));

    expect($result)
        ->status->toBe(AiRequestStatus::Failed)
        ->error_code->toBe(AiErrorCode::FieldRetired)
        ->and($fake->tasks)->toBe([])
        ->and(AiErrorCode::FieldRetired->message())->toBe(__('ai.errors.field_retired'))
        ->and(AiErrorCode::FieldRetired->isRetryable())->toBeFalse();
});

it('lets only the admin manage AI prompts', function (Role $role, bool $allowed) {
    $user = staffUser($role);

    expect($user->can('viewAny', AiPromptOverride::class))->toBe($allowed)
        ->and($user->can('update', AiPromptOverride::class))->toBe($allowed)
        ->and($user->hasPermissionTo('ai-prompts.manage'))->toBe($allowed);
})->with([
    'admin' => [Role::Admin, true],
    'content manager' => [Role::ContentManager, false],
    'sales manager' => [Role::SalesManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('audits AI prompt changes in the integrations group', function () {
    expect(SettingsArea::AiPrompts->group())->toBe('integrations')
        ->and(SettingsArea::AiPrompts->isRestricted())->toBeFalse();

    foreach (config('app.supported_locales') as $locale) {
        expect(__('enums.settings_area.ai_prompts', [], $locale))->not->toBe('enums.settings_area.ai_prompts')
            ->and(__('enums.ai_error_code.field_retired', [], $locale))->not->toBe('enums.ai_error_code.field_retired')
            ->and(__('ai.errors.field_retired', [], $locale))->not->toBe('ai.errors.field_retired');
    }
});
