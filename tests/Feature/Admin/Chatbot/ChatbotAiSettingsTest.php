<?php

use App\Actions\Chat\SaveChatbotSettings;
use App\Actions\Chat\TestChatPhrase;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Livewire\Admin\Chatbot\Console;
use App\Livewire\Admin\Chatbot\Settings as ChatbotSettingsScreen;
use App\Livewire\Admin\Chatbot\Stats;
use App\Models\ChatAiUsageDaily;
use App\Models\Page;
use App\Models\SettingsAudit;
use App\Services\Ai\ChatModel;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Plan 30 §9, P2, AI-1, AI-10, AI-24: the "AI answers" section of Chat bot →
 * Settings, the Stats AI table and the console's "would ask AI".
 */
const AI_ADMIN_KEY = 'sk-ant-test-ADMINKEY-9876543210zyxwvutsrq';

function aiAdminReady(bool $key = true): void
{
    Page::factory()->system('privacy')->create(['is_active' => true, 'needs_review' => false]);
    config(['services.anthropic.key' => $key ? AI_ADMIN_KEY : '']);
}

function aiSave(array $data, Role $role): int
{
    $changed = app(SaveChatbotSettings::class)->handle($data, staffUser($role));
    ChatbotSwitch::forget();

    return $changed;
}

it('shows the key status as a server-computed flag and never the key', function (bool $key) {
    aiAdminReady($key);

    $html = $this->actingAs(staffUser(Role::Admin))->get(route('admin.chatbot.settings'))->assertOk()->getContent();

    expect($html)->toContain('data-ai-settings')
        ->toContain('data-ai-key="'.($key ? 'configured' : 'missing').'"')
        ->not->toContain(AI_ADMIN_KEY)
        ->not->toContain('sk-ant');

    $component = Livewire::actingAs(staffUser(Role::Admin))->test(ChatbotSettingsScreen::class);

    expect(json_encode($component->snapshot))->not->toContain(AI_ADMIN_KEY);
})->with(['configured' => [true], 'missing' => [false]]);

it('lets an Admin switch AI on and off, with audit rows', function () {
    aiAdminReady();

    Livewire::actingAs(staffUser(Role::Admin))->test(ChatbotSettingsScreen::class)
        ->set('form.ai_telegram_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    ChatbotSwitch::forget();
    expect(AiSwitch::isStored(ChatChannel::Telegram))->toBeTrue();

    aiSave(['ai_telegram_enabled' => false], Role::Admin);

    expect(AiSwitch::isStored(ChatChannel::Telegram))->toBeFalse()
        ->and(SettingsAudit::query()->where('key', AiSwitch::keyFor(ChatChannel::Telegram))->count())->toBe(2);
});

it('refuses to switch AI on while the key is missing or the privacy page is not ready', function (bool $key, bool $privacy) {
    config(['services.anthropic.key' => $key ? AI_ADMIN_KEY : '']);

    if ($privacy) {
        Page::factory()->system('privacy')->create(['is_active' => true, 'needs_review' => false]);
    }

    expect(fn () => aiSave(['ai_telegram_enabled' => true], Role::Admin))
        ->toThrow(fn (ValidationException $e) => expect(array_keys($e->errors()))->toBe(['ai_telegram_enabled']));

    expect(AiSwitch::isStored(ChatChannel::Telegram))->toBeFalse();
})->with([
    'no key' => [false, true],
    'no privacy page' => [true, false],
]);

it('keeps the website AI switch closed until the website chat is ready', function () {
    aiAdminReady();

    expect(fn () => aiSave(['ai_web_enabled' => true], Role::Admin))->toThrow(ValidationException::class);
});

it('lets a content manager switch AI off but not on, not the caps and not the instructions', function () {
    aiAdminReady();

    expect(fn () => aiSave(['ai_telegram_enabled' => true], Role::ContentManager))->toThrow(AuthorizationException::class)
        ->and(fn () => aiSave(['ai_daily_requests' => 10], Role::ContentManager))->toThrow(AuthorizationException::class)
        ->and(fn () => aiSave(['ai_daily_tokens' => 10], Role::ContentManager))->toThrow(AuthorizationException::class)
        ->and(fn () => aiSave(['ai_instructions' => 'Be brief.'], Role::ContentManager))->toThrow(AuthorizationException::class);

    aiSave(['ai_telegram_enabled' => true], Role::Admin);
    aiSave(['ai_telegram_enabled' => false], Role::ContentManager);

    expect(AiSwitch::isStored(ChatChannel::Telegram))->toBeFalse();
});

it('answers 403 when a content manager tampers with the AI fields on the screen', function (string $field, mixed $value) {
    aiAdminReady();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(ChatbotSettingsScreen::class)
        ->set('form.'.$field, $value)
        ->call('save')
        ->assertForbidden();
})->with([
    'enable' => ['ai_telegram_enabled', true],
    'caps' => ['ai_daily_requests', 5],
    'instructions' => ['ai_instructions', 'Be brief.'],
]);

it('lets a content manager save the texts without touching the AI fields', function () {
    aiAdminReady();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(ChatbotSettingsScreen::class)
        ->set('form.texts.fallback.uz', 'Tushunmadim, menejerdan soʻrang.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('data-ai-caps-readonly', false);
});

it('validates the instructions and audits them with the old and new text', function () {
    aiAdminReady();

    foreach (['See https://poson.uz', 'Write to @poson_sale', 'Mail info@poson.uz', 'Call +998 90 123 45 67', 'Dealer prices are lower', 'Ulgurji narx bor', 'Цены для опт', 'Skidka yoʻq: chegirma', 'Sayt evil.gg', 'Sayt poson．uz', 'Сайт пример.рф'] as $bad) {
        expect(fn () => aiSave(['ai_instructions' => $bad], Role::Admin))
            ->toThrow(fn (ValidationException $e) => expect(array_keys($e->errors()))->toBe(['ai_instructions']));
    }

    aiSave(['ai_instructions' => 'Javoblar qisqa boʻlsin.'], Role::Admin);
    aiSave(['ai_instructions' => 'Be short and polite.'], Role::Admin);

    $audit = SettingsAudit::query()->where('key', ChatSettings::key('ai_instructions'))->latest('id')->first();

    expect(ChatSettings::all()['ai']['instructions'])->toBe('Be short and polite.')
        ->and($audit->old_text)->toBe('Javoblar qisqa boʻlsin.')
        ->and($audit->new_text)->toBe('Be short and polite.');
});

it('lets an Admin change the daily caps within bounds', function () {
    aiAdminReady();

    aiSave(['ai_daily_requests' => 200, 'ai_daily_tokens' => 400000], Role::Admin);

    expect(ChatSettings::all()['ai']['daily_requests'])->toBe(200)
        ->and(ChatSettings::all()['ai']['daily_tokens'])->toBe(400000)
        ->and(fn () => aiSave(['ai_daily_requests' => -1], Role::Admin))->toThrow(ValidationException::class);
});

it('shows the AI usage table with the estimated cost in Stats', function () {
    ChatAiUsageDaily::factory()->create(['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0]);

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Stats::class)
        ->assertOk()
        ->assertSee('data-ai-stats', false)
        ->assertSee('$0.6000');
});

it('says "would ask AI" in the console without ever resolving the model', function () {
    app()->bind(ChatModel::class, fn () => throw new LogicException('The console must not resolve the chat model.'));

    $result = app(TestChatPhrase::class)->handle(staffUser(Role::ContentManager), 'zorbqiz tarmoqda ishlaydimi', 'uz', ChatChannel::Telegram);

    expect($result['ai']['reason'])->toBe(__('chatbot_admin.console.ai_reasons.no_match'))
        ->and($result['ai']['enabled'])->toBeFalse();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Console::class)
        ->set('phrase', 'zorbqiz tarmoqda ishlaydimi')
        ->call('run')
        ->assertSee('data-console-ai', false);
});
