<?php

use App\Actions\Chat\Web\PruneWebChats;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Models\ChatAiTurn;
use App\Models\Lead;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Support\Chat\WebChatVersion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;

/**
 * Plan 28 C5d: chat:prune retention (CB-25, WC-14, WC-15, W-10).
 */
function pruneChat(string $age, array $attributes = []): WebChat
{
    $at = now()->sub($age);

    $chat = WebChat::factory()->create(array_merge(['last_message_at' => $at, 'created_at' => $at], $attributes));
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'hello']);

    return $chat;
}

it('is scheduled daily at 03:15 Tashkent and described for the commands screen', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'chat:prune') && ! str_contains((string) $e->command, 'chat:prune-ai'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 3 * * *')
        ->and($event->timezone)->toBe('Asia/Tashkent')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();

    $command = app(Kernel::class)->all()['chat:prune'];
    expect($command->getDescription())->not->toBe('')
        ->and((string) (new ReflectionProperty($command, 'help'))->getValue($command))->not->toBe('');
});

it('expires an open chat idle for 24 hours without deleting it and bumps the version', function () {
    $idle = pruneChat('25 hours', ['mode' => ChatMode::Manager, 'handoff_at' => now()->subHours(25), 'dialog' => ['x' => 1]]);
    $fresh = pruneChat('2 hours');

    $counts = app(PruneWebChats::class)->handle();

    expect($counts['expired'])->toBe(1);
    $idle->refresh();
    expect($idle->isClosed())->toBeTrue()
        ->and($idle->mode)->toBe(ChatMode::Bot)
        ->and($idle->dialog)->toBeNull()
        ->and($idle->ai_context_at)->not->toBeNull()
        ->and($idle->messages()->count())->toBe(1)
        ->and(WebChatVersion::get($idle->id))->not->toBeNull()
        ->and($fresh->fresh()->isClosed())->toBeFalse();
});

it('deletes each retention class at its age and no earlier, messages and AI turns with it', function (string $kind, string $young, string $old) {
    $attributes = match ($kind) {
        'bot' => [],
        'handed' => ['handoff_at' => now()->subDays(100)],
        'lead' => ['lead_id' => Lead::factory()->create()->id, 'handoff_at' => now()->subDays(100)],
    };

    $keep = pruneChat($young, $attributes);
    $drop = pruneChat($old, $attributes);
    ChatAiTurn::factory()->create(['channel' => ChatChannel::Web, 'telegram_user_id' => null, 'web_chat_id' => $drop->id]);

    app(PruneWebChats::class)->handle();

    expect(WebChat::query()->whereKey($keep->id)->exists())->toBeTrue()
        ->and(WebChat::query()->whereKey($drop->id)->exists())->toBeFalse()
        ->and(WebChatMessage::query()->where('web_chat_id', $drop->id)->count())->toBe(0)
        ->and(WebChatMessage::query()->where('web_chat_id', $keep->id)->count())->toBe(1)
        ->and(ChatAiTurn::query()->where('web_chat_id', $drop->id)->count())->toBe(0);
})->with([
    'bot-only 30 days' => ['bot', '29 days', '31 days'],
    'handed-off without lead 90 days' => ['handed', '89 days', '91 days'],
    'lead-linked 12 months' => ['lead', '11 months', '13 months'],
]);

it('nulls the ip hash after 30 days only', function () {
    $young = pruneChat('29 days', ['ip_hash' => str_repeat('a', 64), 'handoff_at' => now()]);
    $old = pruneChat('31 days', ['ip_hash' => str_repeat('b', 64), 'handoff_at' => now()]);

    app(PruneWebChats::class)->handle();

    expect($young->fresh()->ip_hash)->not->toBeNull()->and($old->fresh()->ip_hash)->toBeNull();
});

it('purges a banned chat at 30 days (no text, name, phone, page left) and deletes the row at 12 months', function () {
    $recent = pruneChat('2 days', ['banned_at' => now()->subDays(29), 'name' => 'Bad', 'phone' => '+998901234567']);
    $purge = pruneChat('40 days', ['banned_at' => now()->subDays(31), 'name' => 'Bad', 'phone' => '+998901234567', 'first_page' => '/en/x', 'dialog' => ['a' => 1]]);
    $old = pruneChat('13 months', ['banned_at' => now()->subMonths(13)]);

    $counts = app(PruneWebChats::class)->handle();

    expect($counts['purged'])->toBe(1);
    $purge->refresh();
    expect($purge->purged_at)->not->toBeNull()
        ->and($purge->banned_at)->not->toBeNull()
        ->and($purge->messages()->count())->toBe(0)
        ->and([$purge->name, $purge->phone, $purge->first_page, $purge->dialog])->each->toBeNull()
        ->and($recent->fresh()->messages()->count())->toBe(1)
        ->and($recent->fresh()->phone)->not->toBeNull()
        ->and(WebChat::query()->whereKey($old->id)->exists())->toBeFalse();
});

it('keeps a banned chat past the generic ages so the ban keeps blocking', function () {
    $banned = pruneChat('40 days', ['banned_at' => now()->subDays(35)]);

    app(PruneWebChats::class)->handle();

    expect(WebChat::query()->whereKey($banned->id)->exists())->toBeTrue();
});

it('is idempotent and logs counts only', function () {
    pruneChat('31 days');
    Log::spy();

    $this->artisan('chat:prune')->assertSuccessful();
    $second = app(PruneWebChats::class)->handle();

    expect($second)->toBe(['expired' => 0, 'deleted' => 0, 'ip_cleared' => 0, 'purged' => 0]);
    Log::shouldHaveReceived('info')->with('Website chats pruned', Mockery::on(fn ($context) => array_keys($context) === ['expired', 'deleted', 'ip_cleared', 'purged']))->atLeast()->once();
});

it('works with time travel from a fixed clock', function () {
    $chat = WebChat::factory()->create(['last_message_at' => now(), 'created_at' => now()]);

    $this->travel(25)->hours();
    app(PruneWebChats::class)->handle();
    expect($chat->fresh()->isClosed())->toBeTrue();

    $this->travel(31)->days();
    app(PruneWebChats::class)->handle();
    expect(WebChat::query()->whereKey($chat->id)->exists())->toBeFalse();
});
