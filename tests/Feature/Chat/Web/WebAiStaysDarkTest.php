<?php

require_once __DIR__.'/WebChatHelpers.php';

use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\ChatAiTurn;
use App\Models\Setting;
use App\Models\WebChatMessage;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use Illuminate\Validation\ValidationException;

/**
 * Plan 28 C5a, WC-4: the website widget flag (chatbot.web_ready) and the web
 * AI flag (chatbot.ai.web_ready) are separate; web AI stays dark in C5.
 */
function wcEverythingOnButWebAi(): void
{
    wcSetup();
    config(['services.anthropic.key' => 'sk-ant-test-fake', 'chatbot.web_ready' => true]);
    Setting::query()->updateOrCreate(['key' => AiSwitch::keyFor(ChatChannel::Web)], ['group' => SettingsArea::Chatbot->group(), 'value' => true]);
    ChatbotSwitch::forget();
}

it('ships chatbot.ai.web_ready as a literal false', function () {
    expect(config('chatbot.ai.web_ready'))->toBeFalse()
        ->and((string) file_get_contents(config_path('chatbot.php')))->toContain("'web_ready' => false,")
        ->not->toMatch("/'web_ready'\s*=>\s*env\(/");
});

it('ai_web_switch_still_refused_until_ai_web_ready', function () {
    wcEverythingOnButWebAi();

    expect(ChatbotSwitch::isEnabled(ChatChannel::Web))->toBeTrue()
        ->and(AiSwitch::isStored(ChatChannel::Web))->toBeTrue()
        ->and(AiSwitch::isEnabled(ChatChannel::Web))->toBeFalse();

    config(['chatbot.ai.web_ready' => true]);

    expect(AiSwitch::isEnabled(ChatChannel::Web))->toBeTrue();
});

it('refuses an Admin enabling web AI while only the widget is ready', function () {
    wcSetup(defaults: false);
    config(['services.anthropic.key' => 'sk-ant-test-fake', 'chatbot.web_ready' => true]);

    expect(fn () => app(SaveChatbotSettings::class)->handle(['ai_web_enabled' => true], staffUser(Role::Admin)))
        ->toThrow(ValidationException::class);

    expect(AiSwitch::isStored(ChatChannel::Web))->toBeFalse();
});

it('the web engine never returns an AI marker or stores an AI turn with everything else on', function () {
    wcEverythingOnButWebAi();

    foreach (['xyzzy plugh', 'ZD421', 'hello'] as $text) {
        wcSend($text);
    }

    expect(ChatAiTurn::query()->count())->toBe(0)
        ->and(WebChatMessage::query()->where('author', ChatAuthor::Bot)->whereNotNull('chat_ai_turn_id')->count())->toBe(0)
        ->and(WebChatMessage::query()->where('author', ChatAuthor::Bot)->count())->toBe(3);
});
