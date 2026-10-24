<?php

use App\Actions\Settings\TestIntegrationConnection;
use App\Enums\AuditAction;
use App\Enums\IntegrationSource;
use App\Enums\Role;
use App\Livewire\Admin\Settings\Integrations;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Secrets;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const OR_SCREEN_KEY = 'sk-or-v1-fake-ORSCREENKEY-0000000000000000abcd';

function openRouterScreen(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? staffUser())->test(Integrations::class);
}

beforeEach(function () {
    Http::preventStrayRequests();
    freshSecrets();
});

describe('access', function () {
    it('forbids every other staff role', function (Role $role) {
        $this->actingAs(staffUser($role))->get(route('admin.settings.integrations'))->assertForbidden();

        confirmAdminPassword();
        Livewire::actingAs(staffUser($role))->test(Integrations::class)->assertForbidden();
    })->with([[Role::ContentManager], [Role::SalesManager], [Role::ServiceEngineer]]);
});

describe('fields', function () {
    it('shows the OpenRouter fields only when OpenRouter is selected', function () {
        $component = openRouterScreen()
            ->assertSet('form.values.ai.text_provider', 'anthropic')
            ->assertSee('data-setting="ai.text_model"', false)
            ->assertDontSee('data-setting="ai.openrouter_model"', false)
            ->assertDontSee('data-secret="openrouter.api_key"', false);

        $component->set('form.values.ai.text_provider', 'openrouter')
            ->assertSee('data-setting="ai.openrouter_model"', false)
            ->assertSee('data-setting="ai.openrouter_allow_data_collection"', false)
            ->assertSee('data-secret="openrouter.api_key"', false)
            ->assertDontSee('data-setting="ai.text_model"', false)
            ->assertSee('list="openrouter-models"', false)
            ->assertSee('<option value="nvidia/nemotron-3.5-super-vl:free">', false);
    });

    it('warns about free models more strongly than about paid ones', function () {
        $component = openRouterScreen()->set('form.values.ai.text_provider', 'openrouter');

        $component->assertSee('data-openrouter-free-warning', false)
            ->assertSee(__('admin.settings.integrations.openrouter.free_warning_limits'))
            ->assertSee(__('admin.settings.integrations.openrouter.warning_quality'));

        $component->set('form.values.ai.openrouter_model', 'qwen/qwen3.8-flash')
            ->assertDontSee('data-openrouter-free-warning', false)
            ->assertSee('data-openrouter-warning', false);

        $component->set('form.values.ai.text_provider', 'anthropic')
            ->assertDontSee('data-openrouter-warning', false);
    });

    it('saves the provider, a lowercased model and the data collection switch with audit rows', function () {
        confirmAdminPassword();

        openRouterScreen()
            ->set('form.values.ai.text_provider', 'openrouter')
            ->set('form.values.ai.openrouter_model', ' Google/Gemini-3.8-Flash ')
            ->set('form.values.ai.openrouter_allow_data_collection', false)
            ->call('save', 'ai')
            ->assertHasNoErrors();

        expect(config('ai-assist.text_provider'))->toBe('openrouter')
            ->and(config('ai-assist.openrouter_model'))->toBe('google/gemini-3.8-flash')
            ->and(config('ai-assist.openrouter_allow_data_collection'))->toBeFalse()
            ->and(SettingsAudit::query()->pluck('key')->sort()->values()->all())->toBe(['ai.openrouter_allow_data_collection', 'ai.openrouter_model', 'ai.text_provider']);
    });

    it('rejects an invalid model ID or provider', function (string $key, string $value) {
        confirmAdminPassword();

        openRouterScreen()
            ->set('form.values.ai.text_provider', 'openrouter')
            ->set('form.values.'.$key, $value)
            ->call('save', 'ai')
            ->assertHasErrors('form.values.'.$key);

        expect(Setting::query()->count())->toBe(0);
    })->with([
        'no vendor' => ['ai.openrouter_model', 'gpt-6-luna'],
        'spaces' => ['ai.openrouter_model', 'openai/gpt 6'],
        'url' => ['ai.openrouter_model', 'https://openrouter.ai/openai/gpt-6-luna'],
        'too long' => ['ai.openrouter_model', 'vendor/'.str_repeat('a', 80)],
        'unknown provider' => ['ai.text_provider', 'openai'],
    ]);

    it('locks the fields set in .env', function () {
        freshSecrets(['ai-assist.text_provider' => 'openrouter', 'ai-assist.openrouter_model' => 'openai/gpt-6-luna']);
        confirmAdminPassword();

        openRouterScreen()
            ->assertSet('form.values.ai.openrouter_model', 'openai/gpt-6-luna')
            ->set('form.values.ai.openrouter_model', 'qwen/qwen3.8-flash')
            ->call('save', 'ai');

        expect(Secrets::source('ai.openrouter_model'))->toBe(IntegrationSource::Env)
            ->and(Setting::query()->where('key', 'ai.openrouter_model')->exists())->toBeFalse()
            ->and(config('ai-assist.openrouter_model'))->toBe('openai/gpt-6-luna');
    });
});

describe('key', function () {
    it('stores the key encrypted and never shows it, logs it or audits its value', function () {
        confirmAdminPassword();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $component = openRouterScreen()
            ->set('form.values.ai.text_provider', 'openrouter')
            ->update(calls: [['method' => 'replaceSecret', 'params' => ['openrouter.api_key'], 'path' => '']], updates: ['secretInputs.openrouter.api_key' => OR_SCREEN_KEY])
            ->assertHasNoErrors();

        $audit = SettingsAudit::query()->sole();

        expect(Secrets::get('openrouter.api_key'))->toBe(OR_SCREEN_KEY)
            ->and(config('services.openrouter.key'))->toBe(OR_SCREEN_KEY)
            ->and(IntegrationSecret::query()->toBase()->value('value'))->not->toContain('ORSCREENKEY')
            ->and($audit->key)->toBe('openrouter.api_key')
            ->and($audit->action)->toBe(AuditAction::Updated)
            ->and(json_encode($audit->toArray()))->not->toContain('ORSCREENKEY')
            ->and($component->html().json_encode($component->snapshot))->not->toContain('ORSCREENKEY')
            ->and($component->html())->toContain('••••abcd')
            ->and(implode("\n", $logged))->not->toContain('ORSCREENKEY');
    });

    it('refuses to replace a key locked by .env', function () {
        freshSecrets(['services.openrouter.key' => 'sk-or-v1-env-000000000000000000000']);
        confirmAdminPassword();

        openRouterScreen()
            ->update(calls: [['method' => 'replaceSecret', 'params' => ['openrouter.api_key'], 'path' => '']], updates: ['secretInputs.openrouter.api_key' => OR_SCREEN_KEY]);

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(Secrets::get('openrouter.api_key'))->toBe('sk-or-v1-env-000000000000000000000');
    });
});

describe('connection test', function () {
    beforeEach(function () {
        IntegrationSecret::factory()->withValue('openrouter.api_key', OR_SCREEN_KEY)->create();
        Setting::factory()->create(['key' => 'ai.text_provider', 'group' => 'integrations', 'value' => 'openrouter']);
        Secrets::flush();
        confirmAdminPassword();
    });

    it('shows the tier, the limit and that the model exists', function () {
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'sk-or-v1-fak...abcd', 'limit' => null, 'usage' => 0, 'is_free_tier' => true]]),
            'openrouter.ai/api/v1/models/*' => Http::response(['data' => ['id' => 'nvidia/nemotron-3.5-super-vl:free', 'endpoints' => [['name' => 'Nvidia']]]]),
        ]);

        $component = openRouterScreen()->call('test', 'openrouter');

        expect($component->get('testResults.openrouter_api_key.ok'))->toBeTrue()
            ->and($component->get('testResults.openrouter_api_key.message'))->toBe(__('admin.settings.integrations.test.codes.openrouter_ok', [
                'tier' => __('admin.settings.integrations.openrouter.free_tier'),
                'limit' => __('admin.settings.integrations.openrouter.no_limit'),
                'model' => 'nvidia/nemotron-3.5-super-vl:free',
            ]));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/key' && $request->header('Authorization') === ['Bearer '.OR_SCREEN_KEY]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/models/nvidia/nemotron-3.5-super-vl%3Afree/endpoints');
        expect($component->html())->not->toContain('ORSCREENKEY');
    });

    it('counts one rate limiter hit for its two requests', function () {
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['limit' => 5, 'is_free_tier' => false]]),
            'openrouter.ai/api/v1/models/*' => Http::response(['data' => ['endpoints' => [['name' => 'Qwen']]]]),
        ]);
        $admin = staffUser();

        openRouterScreen($admin)->call('test', 'openrouter');

        Http::assertSentCount(2);
        expect(RateLimiter::attempts(TestIntegrationConnection::throttleKey($admin)))->toBe(1);
    });

    it('encodes the model path segments and refuses an invalid configured model', function () {
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['limit' => null, 'is_free_tier' => true]]),
            'openrouter.ai/api/v1/models/*' => Http::response(['data' => ['endpoints' => [['name' => 'Nvidia']]]]),
        ]);

        openRouterScreen()->call('test', 'openrouter');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/models/nvidia/nemotron-3.5-super-vl%3Afree/endpoints'));

        config(['ai-assist.openrouter_model' => 'bad model']);

        $component = openRouterScreen()->call('test', 'openrouter');

        expect($component->get('testResults.openrouter_api_key'))->toBe(['ok' => false, 'message' => __('admin.settings.integrations.test.codes.openrouter_model_invalid')]);
    });

    it('reports a model that does not exist', function () {
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['limit' => 10, 'is_free_tier' => false]]),
            'openrouter.ai/api/v1/models/*' => Http::response(['error' => ['code' => 404, 'message' => 'Not Found']], 404),
        ]);

        $component = openRouterScreen()->call('test', 'openrouter');

        expect($component->get('testResults.openrouter_api_key.ok'))->toBeFalse()
            ->and($component->get('testResults.openrouter_api_key.message'))->toContain('nvidia/nemotron-3.5-super-vl:free');
    });

    it('reports a rejected key without checking the model', function () {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => 401, 'message' => 'No auth credentials found']], 401)]);

        $component = openRouterScreen()->call('test', 'openrouter');

        expect($component->get('testResults.openrouter_api_key'))->toBe(['ok' => false, 'message' => __('admin.settings.integrations.test.codes.unauthorized')]);
        Http::assertSentCount(1);
    });
});
