<?php

require_once __DIR__.'/ChatBotHelpers.php';
require_once __DIR__.'/Web/WebChatHelpers.php';

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Chat\SaveChatRule;
use App\Actions\Chat\Web\SubmitWebChatLead;
use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Events\Chat\WebChatHandedOff;
use App\Listeners\SendLeadToTelegram;
use App\Livewire\Admin\Chatbot\Rules\Form as RuleFormComponent;
use App\Models\ChatRule;
use App\Models\ChatStatDaily;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\WebChat;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Engine;
use App\Support\Chat\HandoffTarget;
use App\Support\Chat\LeadDialog;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\WebChatDialog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Plan 36 I4 (P36-16, P36-17): the hand-off target per rule. A "service"
 * rule opens the service-request dialog instead of a live hand-off; the
 * manager rule, the menu button and the hand-off button are always sales.
 */
function htGlobalTarget(string $target): void
{
    Setting::query()->updateOrCreate(['key' => ChatSettings::key('handoff_target')], ['group' => SettingsArea::Chatbot->group(), 'value' => $target]);
    ChatSettings::forget();
}

function htInboxLines(): int
{
    return TelegramMessage::query()->where('direction', 'in')->count();
}

function htRuleKeyFor(string $text, string $locale = 'en'): ?string
{
    $reply = app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, $locale, $text));

    return $reply->ruleId === null ? null : ChatRule::query()->whereKey($reply->ruleId)->value('key');
}

it('resolves the target from the rule, then the global setting, never from garbage', function (?array $params, ?string $global, string $expected) {
    if ($global !== null) {
        htGlobalTarget($global);
    }

    expect(HandoffTarget::for($params))->toBe($expected);
})->with([
    'rule service' => [['handoff_target' => 'service'], null, 'service'],
    'rule sales beats global service' => [['handoff_target' => 'sales'], 'service', 'sales'],
    'no param, default global' => [null, null, 'sales'],
    'no param, global service' => [[], 'service', 'service'],
    'no param, global any' => [[], 'any', 'sales'],
    'garbage param falls to the global' => [['handoff_target' => 'admin'], null, 'sales'],
    'array param' => [['handoff_target' => ['service']], null, 'sales'],
]);

describe('SaveChatRule', function () {
    beforeEach(fn () => cbSetup());

    function htHandoffInput(mixed $target): array
    {
        return ['key' => 'my_handoff', 'name' => 'My hand-off', 'action' => 'handoff', 'params' => ['handoff_target' => $target], 'keywords' => [['phrase' => 'zzz', 'match_type' => 'word']]];
    }

    it('stores a valid target on a hand-off rule only', function (string $target) {
        $rule = app(SaveChatRule::class)->handle(htHandoffInput($target), staffUser(Role::Admin));

        expect($rule->params)->toBe(['handoff_target' => $target]);
    })->with(['sales', 'service']);

    it('rejects an invalid target', function (mixed $target) {
        expect(fn () => app(SaveChatRule::class)->handle(htHandoffInput($target), staffUser(Role::Admin)))->toThrow(ValidationException::class);
        expect(ChatRule::query()->where('key', 'my_handoff')->exists())->toBeFalse();
    })->with(['admin', 'any', 'SERVICE', [['service']], 1, true]);

    it('drops the target on any other action', function () {
        $input = ['key' => 'my_answer', 'name' => 'Answer', 'action' => 'answer', 'answer' => ['uz' => 'Salom'], 'params' => ['handoff_target' => 'service'],
            'keywords' => [['phrase' => 'zzz', 'match_type' => 'word']]];

        $rule = app(SaveChatRule::class)->handle($input, staffUser(Role::Admin));

        expect($rule->params)->toBeNull();
    });
});

describe('Telegram', function () {
    beforeEach(fn () => cbSetup());

    it('opens the service dialog for a service-target hand-off: bot mode, no inbox line, one service lead', function () {
        Event::fake([WebChatHandedOff::class]);
        $user = cbUser(['locale' => 'ru']);

        cbText('сканер не работает');

        $fresh = $user->fresh();

        expect($fresh->chat_mode)->toBe(ChatMode::Bot)
            ->and($fresh->bot_dialog[LeadDialog::KEY]['flow'] ?? null)->toBe(LeadFlow::Service->value)
            ->and($fresh->unread_count)->toBe(0)
            ->and(htInboxLines())->toBe(0);

        cbText('Не включается, нужен ремонт');
        cbPress(cbButton($user, 'send'));

        $lead = Lead::query()->sole();

        expect($lead->type)->toBe(LeadType::ServiceRequest)
            ->and(SendLeadToTelegram::chatFor($lead))->toBe('service')
            ->and($user->fresh()->chat_mode)->toBe(ChatMode::Bot)
            ->and(htInboxLines())->toBe(0);
    });

    it('falls back to the sales hand-off for an anonymous +888 customer, never a dead end', function () {
        $user = TelegramUser::factory()->anonymousNumber()->create(['telegram_id' => CB_USER, 'locale' => 'ru', 'chat_mode' => ChatMode::Bot]);

        cbText('сканер не работает');

        $fresh = $user->fresh();

        expect($fresh->chat_mode)->toBe(ChatMode::Manager)
            ->and($fresh->bot_dialog[LeadDialog::KEY]['flow'] ?? null)->toBeNull()
            ->and(htInboxLines())->toBe(1)
            ->and(Lead::query()->count())->toBe(0);
    });

    it('opens the service dialog for setup and integration, also with 1C in the text', function (string $text) {
        $user = cbUser(['locale' => 'ru']);

        cbText($text);

        expect($user->fresh()->bot_dialog[LeadDialog::KEY]['flow'] ?? null)->toBe(LeadFlow::Service->value)
            ->and($user->fresh()->chat_mode)->toBe(ChatMode::Bot);
    })->with(['настройка 1С', 'нужна интеграция с 1С', 'установка принтера']);

    it('keeps dealers and availability on the sales inbox', function (string $text) {
        $user = cbUser(['locale' => 'ru']);

        cbText($text);

        expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
            ->and(htInboxLines())->toBe(1);
    })->with(['дилер', 'наличие', 'претензия']);

    it('keeps the manager rule and the hand-off button on sales even when the global setting is service', function () {
        htGlobalTarget('service');
        $user = cbUser(['locale' => 'ru']);

        cbPress('chat:handoff');

        expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
            ->and(htInboxLines())->toBe(1);

        $manager = ChatRule::query()->where('key', 'manager')->sole();
        $reply = app(Engine::class)->respondToRule(new ChatContext(ChatChannel::Telegram, 'ru', ''), $manager->id);

        expect(HandoffTarget::opensServiceDialog($reply, app(RuleIndex::class)->load()))->toBeFalse();
    });

    it('lets the global service setting move only hand-off rules without their own target', function () {
        htGlobalTarget('service');
        cbRule('own_handoff', ['qqqq'], ['action' => ChatAction::Handoff]);
        $user = cbUser(['locale' => 'ru']);

        cbText('qqqq');

        expect($user->fresh()->bot_dialog[LeadDialog::KEY]['flow'] ?? null)->toBe(LeadFlow::Service->value);

        $dealer = cbUser(['locale' => 'ru'], CB_USER + 1);
        cbText('дилер', CB_USER + 1);

        expect($dealer->fresh()->chat_mode)->toBe(ChatMode::Manager);
    });
});

describe('Web chat', function () {
    beforeEach(fn () => wcSetup());

    it('opens the service form for a service-target hand-off: no manager mode, no alert, one lead per chat', function () {
        Event::fake([WebChatHandedOff::class]);

        $posted = wcSend('my scanner is not working');
        $chat = WebChat::query()->sole();

        expect($chat->mode)->toBe(ChatMode::Bot)
            ->and($chat->unread_count)->toBe(0)
            ->and(WebChatDialog::form($chat->dialog)['flow'])->toBe(LeadFlow::Service);
        Event::assertNotDispatched(WebChatHandedOff::class);

        $ruleId = ChatRule::query()->where('key', 'repair_intake')->value('id');

        expect(ChatStatDaily::query()->where('chat_rule_id', $ruleId)->where('metric', ChatMetric::Hit)->where('channel', ChatChannel::Web)->sum('count'))->toBe(1);

        $lead = app(SubmitWebChatLead::class)->handle($chat, 'Ali', '+998901234567', 'Does not power on');
        $again = app(SubmitWebChatLead::class)->handle($chat->fresh(), 'Ali', '+998901234567', 'Again');

        expect($posted->status)->toBe('answered')
            ->and($lead)->toBeInstanceOf(Lead::class)
            ->and($lead->type)->toBe(LeadType::ServiceRequest)
            ->and($again)->toBeNull()
            ->and(Lead::query()->count())->toBe(1);
    });

    it('keeps a sales rule on the live hand-off', function () {
        Event::fake([WebChatHandedOff::class]);

        wcSend('dealer');

        expect(WebChat::query()->sole()->mode)->toBe(ChatMode::Manager);
        Event::assertDispatchedTimes(WebChatHandedOff::class, 1);
    });

    it('opens the service form for setup, and keeps the menu hand-off button on sales under a global service setting', function () {
        htGlobalTarget('service');
        wcSend('setup of 1c');

        expect(WebChatDialog::form(WebChat::query()->sole()->dialog)['flow'])->toBe(LeadFlow::Service);

        $reply = app(Engine::class)->respondToKey(new ChatContext(ChatChannel::Web, 'en', ''), 'manager');

        expect($reply)->toBeInstanceOf(ChatReply::class)
            ->and(HandoffTarget::opensServiceDialog($reply, app(RuleIndex::class)->load()))->toBeFalse();
    });
});

it('has no overlap between setup_integration and the other rules that changes a winner unexpectedly', function (string $text, string $locale, string $key) {
    app(InstallChatbotDefaults::class)->handle();

    expect(htRuleKeyFor($text, $locale))->toBe($key);
})->with([
    ['настройка 1С', 'ru', 'setup_integration'],
    ['интеграция', 'ru', 'setup_integration'],
    ['sozlash kerak', 'uz', 'setup_integration'],
    ['integration with 1c', 'en', 'setup_integration'],
    ['1С', 'ru', 'integration_1c'],
    ['нужен интегратор', 'ru', 'dealer'],
    ['гарантия', 'ru', 'warranty_service'],
    ['сканер не работает', 'ru', 'repair_intake'],
    ['принтер не печатает', 'ru', 'repair_intake'],
    ['настройка принтера этикетки', 'ru', 'setup_integration'],
    ['installation of labels', 'en', 'label_ribbon_fit'],
    ['установка этикеток', 'ru', 'label_ribbon_fit'],
    ['etiketka oʻrnatish', 'uz', 'label_ribbon_fit'],
    ['install driver', 'en', 'product_search'],
    ['гарантия на сканер', 'ru', 'warranty_service'],
    ['yorliq printeri', 'uz', 'product_search'],
    ['skaner ishlamayapti', 'uz', 'repair_intake'],
    ['1C ga ulash kerak', 'uz', 'integration_1c'],
    ['настроить принтер', 'ru', 'setup_integration'],
    ['гарантия и настройка', 'ru', 'setup_integration'],
]);

it('shows the hand-off target select only for a hand-off action', function () {
    Livewire::actingAs(staffUser(Role::Admin))->test(RuleFormComponent::class)
        ->set('form.action', 'answer')
        ->assertDontSeeHtml('id="f-form-params-handoff_target"')
        ->set('form.action', 'handoff')
        ->assertSeeHtml('id="f-form-params-handoff_target"');
});

it('counts the rules the installer creates', function () {
    app(InstallChatbotDefaults::class)->handle();

    expect(DB::table('chat_rules')->where('key', 'setup_integration')->count())->toBe(1)
        ->and(ChatRule::query()->where('key', 'repair_intake')->sole()->params)->toBe(['handoff_target' => 'service'])
        ->and(ChatRule::query()->where('key', 'dealer')->sole()->params)->toBe(['handoff_target' => 'sales']);
});
