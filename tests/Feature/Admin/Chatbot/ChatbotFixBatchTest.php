<?php

use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\ChatChannel;
use App\Enums\KeywordMatch;
use App\Enums\Role;
use App\Livewire\Admin\Chatbot\Rules\Form as RuleFormComponent;
use App\Livewire\Admin\Chatbot\Rules\Index as RulesIndex;
use App\Livewire\Admin\Chatbot\Settings as ChatbotSettingsScreen;
use App\Livewire\Admin\Chatbot\Unmatched\Index as UnmatchedIndex;
use App\Models\ChatRule;
use App\Models\ChatUnmatched;
use App\Models\Page;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;

/**
 * Plan 28 C2 review-fix batch: retention prune, settings memo, stale settings
 * form, teach hand-over check and toasts.
 */
it('prunes open phrases not seen for 180 days and keeps resolved and recent rows', function () {
    $old = ChatUnmatched::factory()->create(['last_seen_at' => now()->subDays(181)]);
    $edge = ChatUnmatched::factory()->create(['last_seen_at' => now()->subDays(179)]);
    $resolved = ChatUnmatched::factory()->create(['last_seen_at' => now()->subDays(400), 'resolved_at' => now()->subDays(300)]);
    $recent = ChatUnmatched::factory()->create();

    $this->artisan('model:prune', ['--model' => [ChatUnmatched::class]])->assertSuccessful();

    expect(ChatUnmatched::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(ChatUnmatched::query()->whereKey([$edge->id, $resolved->id, $recent->id])->count())->toBe(3);
});

it('schedules the unmatched prune daily at 02:00 Tashkent time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'model:prune') && str_contains((string) $e->command, 'ChatUnmatched'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->timezone)->toBe('Asia/Tashkent');
});

it('reads the new texts right after a save in the same process', function () {
    expect(ChatSettings::text('greeting', 'uz'))->toBeNull();

    app(SaveChatbotSettings::class)->handle(['texts' => ['greeting' => ['uz' => 'Yangi salom']]], staffUser(Role::ContentManager));

    expect(ChatSettings::text('greeting', 'uz'))->toBe('Yangi salom');
});

it('does not revert a switch an other Admin changed while a stale settings form is saved', function () {
    Page::factory()->system('privacy')->create(['is_active' => true, 'needs_review' => false]);
    $stale = Livewire::actingAs(staffUser(Role::Admin))->test(ChatbotSettingsScreen::class);

    app(SaveChatbotSettings::class)->handle(['telegram_enabled' => true], staffUser(Role::Admin));

    $stale->set('form.handoff_target', 'any')->call('save')->assertHasNoErrors();

    expect(ChatbotSwitch::isStored(ChatChannel::Telegram))->toBeTrue()
        ->and(ChatSettings::all()['handoff_target'])->toBe('any');
});

it('resolves the teach row from the rule form only when the saved keywords contain the phrase', function (bool $keepPhrase) {
    $row = ChatUnmatched::factory()->create(['normalized' => 'zebra zd421', 'sample' => 'zebra zd421']);

    $component = Livewire::withQueryParams(['keyword' => $row->normalized, 'unmatched' => $row->id])
        ->actingAs(staffUser(Role::ContentManager))
        ->test(RuleFormComponent::class)
        ->set('form.key', 'zebra_topic')
        ->set('form.name', 'Zebra')
        ->set('form.answer.uz', 'Zebra haqida');

    if (! $keepPhrase) {
        $component->set('form.keywords', [['phrase' => 'boshqa gap', 'locale' => '', 'match_type' => 'word', 'weight' => 10]]);
    }

    $component->call('save')->assertHasNoErrors();

    expect($row->fresh()->resolved_at !== null)->toBe($keepPhrase)
        ->and($row->fresh()->resolved_rule_id)->toBe($keepPhrase ? ChatRule::query()->where('key', 'zebra_topic')->value('id') : null);
})->with([true, false]);

it('toasts on ignore only when an open row was closed', function () {
    $open = ChatUnmatched::factory()->create();
    $closed = ChatUnmatched::factory()->create(['resolved_at' => now()]);
    $component = Livewire::actingAs(staffUser(Role::ContentManager))->test(UnmatchedIndex::class);

    $component->call('ignore', $closed->id)->assertNotDispatched('toast');
    $component->call('ignore', $open->id)->assertDispatched('toast');

    expect($open->fresh()->resolved_at)->not->toBeNull();
});

it('toasts when a rule is switched', function () {
    $rule = ChatRule::factory()->create();
    $rule->keywords()->create(['phrase' => 'kafolat', 'normalized' => 'kafolat', 'match_type' => KeywordMatch::Word, 'weight' => 10]);

    Livewire::actingAs(staffUser(Role::ContentManager))->test(RulesIndex::class)
        ->call('toggle', $rule->id)
        ->assertDispatched('toast')
        ->call('toggle', $rule->id)
        ->assertDispatched('toast');

    expect($rule->fresh()->is_active)->toBeTrue();
});
