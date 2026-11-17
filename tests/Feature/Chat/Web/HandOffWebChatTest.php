<?php

require_once __DIR__.'/WebChatHelpers.php';

use App\Actions\Chat\Web\HandOffWebChat;
use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Events\Chat\WebChatHandedOff;
use App\Jobs\SendTelegramMessage;
use App\Listeners\SendTelegramAlerts;
use App\Models\WebChat;
use App\Support\Chat\ChatReply;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatVersion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Plan 28 C5a, WC-13 / CB-22 / W-9: the web hand-off and its sales-chat alert.
 */
beforeEach(fn () => wcSetup(defaults: false));

it('hands off once per waiting period: manager mode, cleared form, new AI context, one system line and one alert', function () {
    Event::fake([WebChatHandedOff::class]);
    $chat = WebChat::factory()->create(['dialog' => WebChatDialog::withForm(LeadFlow::Quote, 5), 'ai_context_at' => null]);

    expect(app(HandOffWebChat::class)->request($chat))->toBeTrue()
        ->and(app(HandOffWebChat::class)->request($chat))->toBeFalse();

    $chat->refresh();

    expect($chat->mode)->toBe(ChatMode::Manager)
        ->and($chat->dialog)->toBeNull()
        ->and($chat->ai_context_at)->not->toBeNull()
        ->and($chat->messages()->where('author', ChatAuthor::System)->count())->toBe(1)
        ->and(WebChatVersion::get($chat->id))->not->toBeNull();

    Event::assertDispatchedTimes(WebChatHandedOff::class, 1);
});

it('refuses a banned or closed chat', function (string $state) {
    $chat = WebChat::factory()->{$state}()->create();

    expect(app(HandOffWebChat::class)->request($chat))->toBeFalse()
        ->and($chat->fresh()->mode)->toBe(ChatMode::Bot);
})->with(['banned', 'closed']);

it('after_hours_handoff_adds_text_and_callback_button', function () {
    $this->travelTo(now('Asia/Tashkent')->next('Sunday')->setTime(3, 0));
    $reply = app(HandOffWebChat::class)->decorate(new ChatReply('A manager will answer.', [], null, ChatReply::HANDOFF), 'en');

    expect($reply->text)->toContain('A manager will answer.')
        ->and(mb_strlen($reply->text))->toBeGreaterThan(mb_strlen('A manager will answer.'))
        ->and(collect($reply->buttons)->pluck('type')->all())->toContain('callback')
        ->and($reply->isHandoff())->toBeTrue();
});

it('handoff_alert_has_no_phone_text_page_or_name_and_an_https_canonical_link', function () {
    config(['app.url' => 'https://poson.uz']);
    app('request')->headers->set('host', 'evil.example');
    $chat = WebChat::factory()->withContact('+998901234567', 'Alisher')->create(['first_page' => '/en/products/secret', 'locale' => 'ru']);
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'my secret text']);

    $message = (new WebChatHandedOff($chat->id))->telegramMessage('en');

    expect($message->html)->toContain('W-'.$chat->id)
        ->toContain('RU')
        ->not->toContain('901234567')
        ->not->toContain('Alisher')
        ->not->toContain('secret')
        ->not->toMatch('/\d{7,}/')
        ->and($message->buttons[0]['url'])->toBe('https://poson.uz/admin/chats/web/'.$chat->id)
        ->and((new WebChatHandedOff($chat->id))->telegramChats())->toBe(['sales']);
});

it('sends no alert for a banned or deleted chat', function () {
    $chat = WebChat::factory()->banned()->create();

    expect((new WebChatHandedOff($chat->id))->telegramMessage('en'))->toBeNull()
        ->and((new WebChatHandedOff(999_999))->telegramMessage('en'))->toBeNull();
});

it('alert_throttle_per_chat_and_global_with_one_limited_notice', function () {
    config(['chatbot.web.alerts_per_hour' => 30]);
    $chats = WebChat::factory()->count(33)->create();

    $messages = $chats->map(fn (WebChat $chat) => (new WebChatHandedOff($chat->id))->telegramMessage('en'));

    expect($messages->take(30)->filter()->count())->toBe(30)
        ->and($messages[30]->html)->toContain(e(__('telegram.web_chat.limited', [], 'en')))
        ->and($messages[31])->toBeNull()
        ->and($messages[32])->toBeNull();
});

it('throttles the per-chat alert to one per ten minutes', function () {
    Event::fake([WebChatHandedOff::class]);
    $chat = WebChat::factory()->create();
    $handoff = app(HandOffWebChat::class);

    $handoff->alert($chat, WebChatHandedOff::REASON_MESSAGE);
    $handoff->alert($chat, WebChatHandedOff::REASON_MESSAGE);
    $this->travel(11)->minutes();
    $handoff->alert($chat, WebChatHandedOff::REASON_MESSAGE);

    Event::assertDispatchedTimes(WebChatHandedOff::class, 2);
});

it('queues the alert through SendTelegramAlerts to the sales chat only, after commit', function () {
    Queue::fake();
    $chat = WebChat::factory()->create();

    app(SendTelegramAlerts::class)->handle(new WebChatHandedOff($chat->id));

    Queue::assertPushed(SendTelegramMessage::class, 1);
    expect((new ReflectionClass(WebChatHandedOff::class))->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue();
});
