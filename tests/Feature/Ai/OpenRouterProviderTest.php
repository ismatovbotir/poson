<?php

use App\Actions\Ai\GenerateFieldText;
use App\Actions\Ai\ProcessAiRequest;
use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Enums\AiRequestStatus;
use App\Enums\Role;
use App\Jobs\RunAiRequest;
use App\Livewire\Admin\Ai\Usage;
use App\Models\AiRequest;
use App\Models\Setting;
use App\Services\Ai\AiAssist;
use App\Services\Ai\AiException;
use App\Services\Ai\AiQuota;
use App\Services\Ai\AiTextProvider;
use App\Services\Ai\TextResult;
use App\Services\Ai\TextTask;
use App\Support\Ai\AiContext;
use App\Support\Secrets;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeTextAssistant;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = staffUser(Role::ContentManager);
});

function queueOpenRouterRequest($user): AiRequest
{
    return app(GenerateFieldText::class)->handle($user, 'product.name', 'Write the name.', new AiContext(name: 'Zebra ZD421'));
}

it('stores the current provider and model on a new request', function () {
    enableOpenRouter(['ai.openrouter_model' => 'qwen/qwen3.8-flash']);
    $fake = fakeTextAssistant(provider: AiProvider::OpenRouter);

    $request = queueOpenRouterRequest($this->user)->fresh();

    expect($request->provider)->toBe(AiProvider::OpenRouter)
        ->and($request->model)->toBe('qwen/qwen3.8-flash')
        ->and($request->status)->toBe(AiRequestStatus::Succeeded)
        ->and($fake->models)->toBe(['qwen/qwen3.8-flash']);
});

it('uses the default Nemotron free model when none is chosen', function () {
    enableOpenRouter();

    expect(AiTextProvider::current())->toBe(AiProvider::OpenRouter)
        ->and(AiTextProvider::model())->toBe('nvidia/nemotron-3.5-super-vl:free');
});

it('needs the key of the selected provider only', function () {
    enableAiAssist(['ai.text_provider' => 'openrouter']);

    // The Anthropic key is set, the OpenRouter one is not.
    expect(AiAssist::isConfigured())->toBeFalse();

    try {
        queueOpenRouterRequest($this->user);
        test()->fail('Expected an AiException.');
    } catch (AiException $exception) {
        expect($exception->errorCode)->toBe(AiErrorCode::NotConfigured);
    }

    enableOpenRouter();

    expect(AiAssist::isConfigured())->toBeTrue();
});

it('runs a queued request on the provider and model of its row after the provider was switched', function () {
    Queue::fake();
    enableOpenRouter(['ai.openrouter_model' => 'qwen/qwen3.8-flash']);

    $request = queueOpenRouterRequest($this->user);
    Queue::assertPushed(RunAiRequest::class);

    // The admin switches back to Anthropic before the worker picks the job up.
    Setting::query()->where('key', 'ai.text_provider')->update(['value' => json_encode('anthropic')]);
    Secrets::flush();
    expect(AiTextProvider::current())->toBe(AiProvider::Anthropic);

    $claude = fakeTextAssistant(new FakeTextAssistant, AiProvider::Anthropic);
    $openRouter = fakeTextAssistant(new FakeTextAssistant(texts: ['uz' => 'OR uz', 'ru' => 'OR ru', 'en' => 'OR en']), AiProvider::OpenRouter);

    app(ProcessAiRequest::class)->handle($request->id, new TextTask(AiFeature::TextGenerate, 'product.name', 'Write the name.', new AiContext));

    expect($claude->tasks)->toBeEmpty()
        ->and($openRouter->models)->toBe(['qwen/qwen3.8-flash'])
        ->and($request->fresh())
        ->provider->toBe(AiProvider::OpenRouter)
        ->model->toBe('qwen/qwen3.8-flash')
        ->response->toBe(['uz' => 'OR uz', 'ru' => 'OR ru', 'en' => 'OR en']);
});

it('runs free-model requests at no cost and estimates them at 0 for the budget', function () {
    enableOpenRouter(['ai.monthly_budget_usd' => 1]);
    fakeTextAssistant();

    expect(AiQuota::estimateMicroUsd('nvidia/nemotron-3.5-super-vl:free'))->toBe(0)
        ->and(AiQuota::estimateMicroUsd('some/unknown-model'))->toBe(AiQuota::estimateMicroUsd('claude-opus-5-5'))
        ->and(AiQuota::estimateMicroUsd('qwen/qwen3.8-flash'))->toBe((int) round(6000 * 0.15 + 8192 * 0.47));

    // A spent budget still blocks; open free requests reserve nothing.
    AiRequest::factory()->pending()->for($this->user)->count(5)->create(['provider' => AiProvider::OpenRouter, 'model' => 'nvidia/nemotron-3.5-super-vl:free']);

    expect(AiQuota::monthCostMicroUsd())->toBe(0)
        ->and(queueOpenRouterRequest($this->user)->fresh()->cost_micro_usd)->toBe(0);
});

it('stores the exact provider cost of a result', function () {
    enableOpenRouter(['ai.openrouter_model' => 'qwen/qwen3.8-flash']);

    $fake = new class extends FakeTextAssistant
    {
        public function generate(TextTask $task, string $model): TextResult
        {
            $result = parent::generate($task, $model);

            return new TextResult($result->texts, $model, 10, 20, 1234);
        }
    };
    fakeTextAssistant($fake);

    expect(queueOpenRouterRequest($this->user)->fresh()->cost_micro_usd)->toBe(1234);
});

it('stores the billed cost of a failed answer', function () {
    enableOpenRouter();
    fakeTextAssistant(new FakeTextAssistant(throw: AiException::for(AiErrorCode::InvalidOutput, 900, 100, 'qwen/qwen3.8-flash', 77)));

    expect(queueOpenRouterRequest($this->user)->fresh())
        ->status->toBe(AiRequestStatus::Failed)
        ->error_code->toBe(AiErrorCode::InvalidOutput)
        ->cost_micro_usd->toBe(77);
});

it('marks no-credit and unavailable-model failures as not retryable', function () {
    expect(AiErrorCode::NoCredits->isRetryable())->toBeFalse()
        ->and(AiErrorCode::ModelUnavailable->isRetryable())->toBeFalse()
        ->and(AiErrorCode::NoCredits->message())->not->toBe('ai.errors.no_credits')
        ->and(AiErrorCode::ModelUnavailable->label())->not->toBe('enums.ai_error_code.model_unavailable');
});

it('shows provider and model in the request log', function () {
    AiRequest::factory()->for($this->user)->create(['provider' => AiProvider::OpenRouter, 'model' => 'nvidia/nemotron-3.5-super-vl:free']);

    Livewire::actingAs(staffUser(Role::Admin))
        ->test(Usage::class)
        ->assertSee('OpenRouter')
        ->assertSee('nvidia/nemotron-3.5-super-vl:free');
});

it('sanitizes an OpenRouter answer and flags banned phrases, through the real assistant', function () {
    enableOpenRouter(['ai.openrouter_model' => 'qwen/qwen3.8-flash']);

    $html = '<p>Принтер Zebra ZD421 со скидкой</p><script>alert(1)</script><img src=x onerror=alert(2)>';
    Http::fake(['openrouter.ai/*' => Http::response([
        'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['uz' => '<p>Zebra ZD421 yorliq printeri</p>', 'ru' => $html, 'en' => '<p>Zebra ZD421 label printer</p>'], JSON_UNESCAPED_UNICODE)]]],
        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200, 'cost' => 0.0005],
    ])]);

    $request = app(GenerateFieldText::class)->handle($this->user, 'product.description', 'Write the description.', new AiContext(name: 'Zebra ZD421'))->fresh();

    expect($request->status)->toBe(AiRequestStatus::Succeeded)
        ->and($request->provider)->toBe(AiProvider::OpenRouter)
        ->and($request->response['ru'])->toContain('Принтер Zebra ZD421')
        ->and($request->response['ru'])->not->toContain('<script')
        ->and($request->response['ru'])->not->toContain('onerror')
        ->and($request->brand_warnings)->toHaveKey('ru')
        ->and($request->brand_warnings)->not->toHaveKey('en')
        ->and($request->cost_micro_usd)->toBe(max(500, (int) round(1000 * 0.15 + 200 * 0.47)));
});

it('refuses new requests while the configured model is invalid', function () {
    Exceptions::fake();
    enableOpenRouter();
    config(['ai-assist.openrouter_model' => 'Not A Model']);

    try {
        queueOpenRouterRequest($this->user);
        test()->fail('Expected an AiException.');
    } catch (AiException $exception) {
        expect($exception->errorCode)->toBe(AiErrorCode::NotConfigured);
    }

    expect(AiRequest::query()->count())->toBe(0);
});

it('blocks even free models once the monthly budget is reached', function () {
    enableOpenRouter(['ai.monthly_budget_usd' => 1]);
    fakeTextAssistant();
    AiRequest::factory()->for($this->user)->create(['cost_micro_usd' => AiRequest::MICRO]);

    try {
        queueOpenRouterRequest($this->user);
        test()->fail('Expected an AiException.');
    } catch (AiException $exception) {
        expect($exception->errorCode)->toBe(AiErrorCode::BudgetExceeded);
    }
});
