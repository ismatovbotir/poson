<?php

require_once __DIR__.'/WidgetHelpers.php';

use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Events\LeadCreated;
use App\Events\WebChatHandedOff;
use App\Models\Lead;
use App\Models\Setting;
use App\Support\Chat\WebChatDialog;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;

/**
 * Plan 40 (YD-3): chat_started, chat_handoff and lead_chat from the website chat widget.
 */
beforeEach(fn () => wcSetup());

function wgGoalsOn(bool $on = true): void
{
    Setting::factory()->setting('tracking', ['enabled' => $on, 'ga4' => '', 'metrika' => '98765432'])->create();
    config(['site.analytics_force' => true]);
}

/**
 * @return list<string>
 */
function wgGoals(Testable $widget): array
{
    return collect(data_get($widget->effects, 'dispatches', []))
        ->where('name', 'poson-goal')
        ->map(fn (array $dispatch): string => (string) $dispatch['params']['goal'])
        ->values()
        ->all();
}

it('sends chat_started for the message that opened the chat, then nothing for the next one', function () {
    wgGoalsOn();
    $widget = wgWidget();

    expect(wgGoals($widget->call('send', 'hello')))->toBe(['chat_started'])
        ->and(wgGoals($widget->call('send', 'and another question')))->toBe([]);
});

it('sends chat_handoff once when the visitor asks for a manager', function () {
    wgGoalsOn();
    Event::fake([WebChatHandedOff::class]);
    [$chat, $token] = wcChat();

    $widget = wgWidget($token);

    expect(wgGoals($widget->call('handoff')))->toBe(['chat_handoff'])
        ->and($chat->fresh()->mode)->toBe(ChatMode::Manager)
        ->and(wgGoals(wgWidget($token)->call('handoff')))->toBe([]);
});

it('sends lead_chat for a stored chat lead, never for a bot', function () {
    wgGoalsOn();
    Event::fake([LeadCreated::class]);
    [, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
    $this->travel(5)->seconds();

    $widget = wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertHasNoErrors();

    expect(wgGoals($widget))->toBe(['lead_chat'])->and(Lead::query()->count())->toBe(1);

    [, $botToken] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
    $this->travel(5)->seconds();
    $bot = wgWidget($botToken)->set('name', 'Ali')->set('phone', '90 765 43 21')->set('fax_ext', 'x')->call('submitLead');

    expect(wgGoals($bot))->toBe([]);
});

it('sends no chat goals while tracking is off', function () {
    wgGoalsOn(false);

    expect(wgGoals(wgWidget()->call('send', 'hello')))->toBe([]);
});
