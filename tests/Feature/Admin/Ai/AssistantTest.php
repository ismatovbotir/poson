<?php

use App\Actions\Ai\FailStaleAiRequest;
use App\Actions\Ai\ProcessAiRequest;
use App\Ai\Agents\FieldWriter;
use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Enums\AiRequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\Ai\Assistant;
use App\Livewire\Admin\Ai\Usage;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\Faq\FaqItems;
use App\Livewire\Admin\Products\Form as ProductForm;
use App\Models\AiRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\IntegrationSecret;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Ai\AiException;
use App\Services\Ai\ClaudeTextAssistant;
use App\Services\Ai\TextAssistant;
use App\Services\Ai\TextTask;
use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeTextAssistant;

/**
 * @return Testable
 */
function openAssistant(string $field = 'product.name', string $locale = 'uz', array $current = [], string $target = 'form.name')
{
    return Livewire::test(Assistant::class)->call('openFor', 'form-component-1', $target, $field, $locale, ['name' => 'Zebra ZD421', 'brand' => 'Zebra'], $current);
}

beforeEach(function () {
    enableAiAssist();
    $this->fake = fakeTextAssistant();
    $this->actingAs($this->user = staffUser(Role::ContentManager));
});

describe('assistant flow', function () {
    it('opens with the hint from the registry', function () {
        openAssistant()
            ->assertSet('open', true)
            ->assertSet('fieldKey', 'product.name')
            ->assertSet('mode', 'generate')
            ->assertSet('hint', fn (string $hint): bool => $hint === FieldRegistry::get('product.name')->hintFor(new AiContext(name: 'Zebra ZD421', brand: 'Zebra')) && str_contains($hint, 'Zebra ZD421'))
            ->assertSee(__('ai.assistant.title'));
    });

    it('generates, previews three cards and applies all', function () {
        $component = openAssistant()
            ->set('hint', 'Short name please.')
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-ai-card="uz"')
            ->assertSeeHtml('data-ai-card="ru"')
            ->assertSeeHtml('data-ai-card="en"')
            ->assertSee('Принтер этикеток Zebra ZD421')
            ->assertSee(__('ai.assistant.review_uz'))
            ->assertSeeHtml('data-ai-review-note')
            ->assertSee('27/120');

        $request = AiRequest::query()->sole();
        expect($request->prompt)->toBe('Short name please.')
            ->and($request->feature)->toBe(AiFeature::TextGenerate)
            ->and($this->fake->tasks[0]->context->name)->toBe('Zebra ZD421');

        $component->call('applyAll')
            ->assertDispatched('ai-apply', owner: 'form-component-1', target: 'form.name', field: 'product.name', values: $request->response)
            ->assertSet('open', false);
    });

    it('applies one locale only', function () {
        openAssistant()->call('generate')
            ->call('apply', 'ru')
            ->assertDispatched('ai-apply', owner: 'form-component-1', target: 'form.name', field: 'product.name', values: ['ru' => 'Принтер этикеток Zebra ZD421']);
    });

    it('translates from the active locale and never applies the source card', function () {
        openAssistant('product.short_description', 'ru', ['uz' => '', 'ru' => 'Надёжный принтер этикеток.', 'en' => ''], 'form.short_description')
            ->set('mode', 'translate')
            ->assertSet('hint', FieldRegistry::get('product.short_description')->translateHintFor('ru'))
            ->call('generate')
            ->assertSee(__('ai.assistant.source'))
            ->assertDontSeeHtml('data-ai-apply="ru"')
            ->assertSeeHtml('data-ai-apply="uz"')
            ->call('applyAll')
            ->assertDispatched('ai-apply', fn (string $name, array $params): bool => ! array_key_exists('ru', $params['values']) && isset($params['values']['uz'], $params['values']['en']));

        $request = AiRequest::query()->sole();
        expect($request->feature)->toBe(AiFeature::TextTranslate)
            ->and($request->source_locale)->toBe('ru')
            ->and($this->fake->tasks[0]->sourceText)->toBe('Надёжный принтер этикеток.');
    });

    it('does not offer translate when the active locale is empty', function () {
        openAssistant(current: ['uz' => '', 'ru' => '', 'en' => ''])
            ->set('mode', 'translate')
            ->assertSet('mode', 'generate');
    });

    it('shows brand warnings in amber', function () {
        fakeTextAssistant(new FakeTextAssistant(['uz' => 'Eng arzon printer', 'ru' => 'Принтер', 'en' => 'Printer']));

        openAssistant()->call('generate')
            ->assertSeeHtml('data-ai-warning')
            ->assertSeeHtml('<mark class="rounded bg-amber-100 px-0.5 text-amber-900" data-brand-warning>arzon</mark>');

        expect(AiRequest::query()->sole()->brand_warnings)->toBe(['uz' => ['arzon']]);
    });

    it('shows a translated timeout error with Retry', function () {
        fakeTextAssistant(new FakeTextAssistant(throw: AiException::for(AiErrorCode::Timeout)));

        $component = openAssistant()->call('generate')
            ->assertSee(__('ai.errors.timeout'))
            ->assertSeeHtml('data-ai-retry');

        expect(AiRequest::query()->sole())->status->toBe(AiRequestStatus::Failed)->error_code->toBe(AiErrorCode::Timeout);

        fakeTextAssistant();
        $component->call('retry')->assertDontSee(__('ai.errors.timeout'))->assertSeeHtml('data-ai-results');
        expect(AiRequest::count())->toBe(2);
    });

    it('shows the quota message without Retry', function () {
        enableAiAssist(['ai.daily_text_limit' => 0]);

        openAssistant()->call('generate')
            ->assertSee(__('ai.errors.quota_exceeded'))
            ->assertDontSeeHtml('data-ai-retry');
    });

    it('polls an open request and times out a stale one', function () {
        $request = AiRequest::factory()->pending()->for($this->user)->createdAt(now()->subMinutes(10))->create();

        $component = openAssistant();
        $component->instance()->requestId = $request->id;
        unset($component->instance()->request);

        expect($component->instance()->render()->getData()['pending'])->toBeTrue();

        $component->instance()->refreshRequest(app(FailStaleAiRequest::class));

        expect($request->fresh()->error_code)->toBe(AiErrorCode::Timeout)
            ->and($component->instance()->render()->getData()['error'])->toBe(AiErrorCode::Timeout);
    });

    it('marks a request that waited too long as timed out', function () {
        $request = AiRequest::factory()->pending()->for($this->user)->createdAt(now()->subMinutes(10))->create();

        expect(app(FailStaleAiRequest::class)->handle($request))->toBeTrue()
            ->and($request->fresh()->error_code)->toBe(AiErrorCode::Timeout);
    });
});

describe('forms', function () {
    it('opens the assistant from the category form with the unsaved state', function () {
        $parent = Category::factory()->named(['uz' => 'Printerlar', 'en' => 'Printers'])->create();

        Livewire::test(CategoryForm::class)
            ->set('form.name.uz', 'Yorliq printerlari')
            ->set('form.parent_id', (string) $parent->id)
            ->call('openAi', 'form.description', 'ru')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'category.description'
                && $params['target'] === 'form.description'
                && $params['locale'] === 'ru'
                && $params['context']['name'] === 'Yorliq printerlari'
                && $params['context']['category'] === 'Printers');
    });

    it('builds the product context with brand and specs, never prices', function () {
        $brand = Brand::factory()->create(['name' => 'Zebra']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'model' => 'ZD421', 'price' => 125_000_000]);

        Livewire::test(ProductForm::class, ['product' => $product])
            ->call('openAi', 'form.meta_title', 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'product.meta_title'
                && $params['context']['brand'] === 'Zebra'
                && $params['context']['facts']['Model'] === 'ZD421'
                && $params['context']['subject_type'] === 'product'
                && ! str_contains(json_encode($params['context']), '1250000'));
    });

    it('applies only whitelisted targets of the same component', function () {
        $component = Livewire::test(CategoryForm::class);
        $id = $component->id();

        $component->call('applyAi', $id, 'form.name', 'category.name', ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'xx' => 'no'])
            ->assertSet('form.name.uz', 'Skanerlar')
            ->assertSet('form.name.ru', 'Сканеры')
            ->call('applyAi', $id, 'form.slug', 'category.name', ['uz' => 'hacked'])
            ->assertSet('form.slug', '')
            ->call('applyAi', $id, 'form.is_active', 'category.name', ['uz' => 'x'])
            ->assertSet('form.is_active', true)
            ->call('applyAi', 'another-component', 'form.description', 'category.description', ['uz' => 'Other'])
            ->assertSet('form.description.uz', '')
            // A text written for another field isn't applied (e.g. a meta title into the description).
            ->call('applyAi', $id, 'form.description', 'category.meta_title', ['uz' => 'Title'])
            ->assertSet('form.description.uz', '');
    });

    it('ignores openAi for an unknown target', function () {
        Livewire::test(CategoryForm::class)->call('openAi', 'form.slug', 'uz')->assertNotDispatched('ai-open');
    });

    it('fills a FAQ answer while editing', function () {
        $category = Category::factory()->create();

        $component = Livewire::test(FaqItems::class, ['category' => $category])->call('create')->set('form.question.uz', 'Kafolat qancha?');

        $component->call('openAi', 'form.answer', 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'faq.answer' && $params['context']['question'] === 'Kafolat qancha?')
            ->call('applyAi', $component->id(), 'form.answer', 'faq.answer', ['uz' => 'Kafolat ishlab chiqaruvchining rasmiy kafolati.'])
            ->assertSet('form.answer.uz', 'Kafolat ishlab chiqaruvchining rasmiy kafolati.');

        expect(FaqItem::count())->toBe(0);
    });

    it('shows the AI button and the assistant to a content manager', function () {
        $this->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSee('data-ai-button="category.name"', false)
            ->assertSee('data-ai-button="category.meta_description"', false)
            ->assertSee('data-ai-assistant', false);
    });

    it('hides the AI button when AI is off or not configured', function (bool $enabled, bool $key) {
        freshSecrets();
        IntegrationSecret::query()->delete();
        Setting::query()->where('group', 'integrations')->delete();
        enableAiAssist(['ai.enabled' => $enabled], withKey: $key);

        $this->get(route('admin.categories.create'))
            ->assertOk()
            ->assertDontSee('data-ai-button', false)
            ->assertDontSee('data-ai-assistant', false);
    })->with([
        'disabled' => [false, true],
        'no key' => [true, false],
    ]);
});

describe('access', function () {
    it('forbids the assistant to roles without ai.use', function (Role $role) {
        $this->actingAs(staffUser($role));

        Livewire::test(Assistant::class)->assertForbidden();
    })->with([
        'sales manager' => [Role::SalesManager],
        'service engineer' => [Role::ServiceEngineer],
    ]);

    it('hides the AI button from roles without ai.use', function () {
        $this->actingAs(staffUser(Role::ServiceEngineer));

        $this->get(route('admin.products.create'))
            ->assertOk()
            ->assertDontSee('data-ai-button', false)
            ->assertDontSee('data-ai-assistant', false);
    });

    it('only shows the user their own requests', function () {
        $other = AiRequest::factory()->create();

        $component = openAssistant();
        $component->instance()->requestId = $other->id;

        expect($component->instance()->request)->toBeNull();
    });
});

describe('secrets', function () {
    it('never puts the key in the HTML, the Livewire snapshot, the log row or the logs', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        Exceptions::fake();

        // Real adapter; the provider error echoes the key.
        app()->forgetInstance(TextAssistant::class);
        app()->bind(TextAssistant::class, ClaudeTextAssistant::class);
        FieldWriter::fake(fn () => throw new RuntimeException('401 invalid x-api-key: '.fakeAnthropicKey()));

        $this->actingAs(staffUser(Role::Admin));
        $page = $this->get(route('admin.categories.create'))->assertOk()->getContent();

        $component = openAssistant()->call('generate')->assertSee(__('ai.errors.provider_error'));

        $usage = $this->get(route('admin.ai.usage'))->getContent();
        $request = AiRequest::query()->sole();

        $key = fakeAnthropicKey();
        expect($page)->not->toContain($key)
            ->and($usage)->not->toContain($key)
            ->and($component->html())->not->toContain($key)
            ->and(json_encode($component->snapshot))->not->toContain($key)
            ->and(json_encode($request->toArray()))->not->toContain($key)
            ->and(implode("\n", $logged))->not->toContain($key)
            ->and($request->error_code)->toBe(AiErrorCode::ProviderError);

        Exceptions::assertNotReported(fn (Throwable $e): bool => str_contains($e->getMessage(), $key));
    });
});

describe('review fixes', function () {
    it('disables Apply for a plain text longer than the field maximum', function () {
        fakeTextAssistant(new FakeTextAssistant(['uz' => str_repeat('a', 121), 'ru' => 'Принтер', 'en' => 'Printer']));

        openAssistant()->call('generate')
            ->assertSeeHtml('data-ai-too-long')
            ->assertSee(__('ai.assistant.too_long', ['max' => 120]))
            ->call('apply', 'uz')
            ->assertNotDispatched('ai-apply')
            ->call('applyAll')
            ->assertDispatched('ai-apply', fn (string $name, array $params): bool => ! isset($params['values']['uz']) && isset($params['values']['ru']));
    });

    it('shows an error for an unknown locale', function () {
        openAssistant(locale: 'de')
            ->assertSet('locale', 'uz')
            ->assertHasErrors('locale')
            ->assertSee(__('ai.errors.unknown_locale'));
    });

    it('keeps the subject only for a record of the field kind the user may view', function () {
        $product = Product::factory()->create();
        $category = Category::factory()->create();

        $open = fn (string $field, string $type, int $id) => Livewire::test(Assistant::class)
            ->call('openFor', 'form-1', 'form.name', $field, 'uz', ['name' => 'X', 'subject_type' => $type, 'subject_id' => $id], [])
            ->get('context');

        expect($open('product.name', 'product', $product->id))->subject_type->toBe('product')->subject_id->toBe($product->id)
            ->and($open('product.name', 'category', $category->id))->subject_type->toBeNull()
            ->and($open('faq.answer', 'category', $category->id))->subject_type->toBe('category')
            ->and($open('product.name', 'product', 999999))->subject_id->toBeNull();
    });

    it('checks ai.use on every request, not only on mount', function (string $method, array $args) {
        $component = openAssistant()->call('generate');

        $this->user->syncRoles([Role::SalesManager->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->unsetRelation('roles')->unsetRelation('permissions');

        $component->call($method, ...$args)->assertForbidden();
    })->with([
        'poll' => ['refreshRequest', []],
        'apply' => ['apply', ['ru']],
        'apply all' => ['applyAll', []],
        'reset hint' => ['resetHint', []],
        'mode' => ['$set', ['mode', 'translate']],
    ]);

    it('checks the usage permission on every request', function () {
        $admin = staffUser(Role::Admin);
        $this->actingAs($admin);
        AiRequest::factory()->create();

        $component = Livewire::test(Usage::class)->assertOk();

        $admin->syncRoles([Role::ContentManager->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->unsetRelation('roles')->unsetRelation('permissions');

        $component->set('status', 'failed')->assertForbidden();
    });
});

describe('request lifecycle', function () {
    it('runs a request that waited in a backlog and is not failed while running', function () {
        $request = AiRequest::factory()->pending()->for($this->user)->createdAt(now()->subMinutes(10))->create();
        $stale = null;

        fakeTextAssistant(new FakeTextAssistant(during: function () use ($request, &$stale): void {
            $stale = app(FailStaleAiRequest::class)->handle($request->fresh());
        }));

        app(ProcessAiRequest::class)->handle($request->id, new TextTask(AiFeature::TextGenerate, 'product.name', 'x', new AiContext));

        expect($stale)->toBeFalse()
            ->and($request->fresh()->status)->toBe(AiRequestStatus::Succeeded);
    });

    it('keeps a failed state when the stale check won the race', function () {
        $request = AiRequest::factory()->pending()->for($this->user)->create();

        fakeTextAssistant(new FakeTextAssistant(during: function () use ($request): void {
            $this->travel(10)->minutes();
            app(FailStaleAiRequest::class)->handle($request->fresh());
        }));

        app(ProcessAiRequest::class)->handle($request->id, new TextTask(AiFeature::TextGenerate, 'product.name', 'x', new AiContext));

        expect($request->fresh())->status->toBe(AiRequestStatus::Failed)->error_code->toBe(AiErrorCode::Timeout)->response->toBeNull();
    });

    it('runs a pending request only once', function () {
        $request = AiRequest::factory()->pending()->for($this->user)->create();
        $task = new TextTask(AiFeature::TextGenerate, 'product.name', 'x', new AiContext);

        app(ProcessAiRequest::class)->handle($request->id, $task);
        app(ProcessAiRequest::class)->handle($request->id, $task);

        expect($this->fake->tasks)->toHaveCount(1);
    });

    it('stores tokens and cost on a failed row', function () {
        fakeTextAssistant(new FakeTextAssistant(throw: AiException::for(AiErrorCode::InvalidOutput, 900, 300, 'claude-opus-5-5')));

        openAssistant()->call('generate')->assertSee(__('ai.errors.invalid_output'));

        expect(AiRequest::query()->sole())
            ->status->toBe(AiRequestStatus::Failed)
            ->input_tokens->toBe(900)
            ->output_tokens->toBe(300)
            ->cost_micro_usd->toBe(900 * 4 + 300 * 20);
    });
});
