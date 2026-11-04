<?php

use App\Actions\Telegram\DeleteConversation;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Jobs\DeleteForwardedCopies;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\InboxChat;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Plan 21 B4 (TG-27 amended, T21-9): best-effort removal of staff-chat copies
 * on an Admin delete, from stored pairs only.
 */
const COPY_OLD_CHAT = -1002222222222;

const COPY_NEW_CHAT = '-1003333333333';

beforeEach(function () {
    telegramBotConfigured();
    copyDeleteBotSwitch(true);
});

function copyDeleteTelegramOk(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
}

function copyDeleteBotSwitch(bool $on): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

function copyDeleteRow(TelegramUser $chat, ?int $chatId, ?int $messageId, int $ageHours = 1): TelegramMessage
{
    $row = TelegramMessage::factory()->for($chat)->create();
    $row->forceFill(['forwarded_chat_id' => $chatId, 'forwarded_message_id' => $messageId, 'created_at' => now()->subHours($ageHours)])->save();

    return $row;
}

/**
 * @return list<array{chat_id: mixed, message_id: mixed}>
 */
function copyDeleteCalls(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/deleteMessage'))
        ->map(fn (array $pair) => ['chat_id' => $pair[0]['chat_id'], 'message_id' => $pair[0]['message_id']])
        ->values()
        ->all();
}

it('deletes a stored copy and header, after the rows are gone, and the job holds ids only', function () {
    copyDeleteTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create([
        'forward_header_chat_id' => COPY_OLD_CHAT,
        'forward_header_message_id' => 900,
        'forward_header_at' => now()->subHour(),
    ]);
    copyDeleteRow($chat, COPY_OLD_CHAT, 901);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    expect(TelegramUser::query()->find($chat->id))->toBeNull()
        ->and(TelegramMessage::query()->count())->toBe(0)
        ->and(copyDeleteCalls())->toBe([
            ['chat_id' => (string) COPY_OLD_CHAT, 'message_id' => 901],
            ['chat_id' => (string) COPY_OLD_CHAT, 'message_id' => 900],
        ]);
});

it('queues the job with integer pairs only and nothing else', function () {
    Bus::fake([DeleteForwardedCopies::class]);
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create(['name' => 'Private Person']);
    copyDeleteRow($chat, COPY_OLD_CHAT, 901);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    Bus::assertDispatched(DeleteForwardedCopies::class, fn (DeleteForwardedCopies $job) => $job->pairs === [[COPY_OLD_CHAT, 901]]
        && $job->tries === 1
        && ! str_contains(serialize($job), 'Private Person'));
});

it('queues nothing when there is no stored pair, and the delete itself still works', function () {
    Bus::fake([DeleteForwardedCopies::class]);
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create();

    app(DeleteConversation::class)->handle($chat, $admin, null);

    Bus::assertNotDispatched(DeleteForwardedCopies::class);
    expect(TelegramUser::query()->find($chat->id))->toBeNull();
});

it('never calls a pair over 47 h and calls one just under it', function () {
    copyDeleteTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create([
        'forward_header_chat_id' => COPY_OLD_CHAT,
        'forward_header_message_id' => 900,
        'forward_header_at' => now()->subHours(47)->subMinute(),
    ]);
    copyDeleteRow($chat, COPY_OLD_CHAT, 901, ageHours: 48);
    $edge = copyDeleteRow($chat, COPY_OLD_CHAT, 902);
    $edge->forceFill(['created_at' => now()->subHours(47)])->save();
    $young = copyDeleteRow($chat, COPY_OLD_CHAT, 903);
    $young->forceFill(['created_at' => now()->subHours(47)->addMinute()])->save();

    app(DeleteConversation::class)->handle($chat, $admin, null);

    expect(copyDeleteCalls())->toBe([['chat_id' => (string) COPY_OLD_CHAT, 'message_id' => 903]]);
});

it('never calls a row missing either value of a pair', function () {
    copyDeleteTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create([
        'forward_header_chat_id' => null,
        'forward_header_message_id' => 900,
        'forward_header_at' => now()->subHour(),
    ]);
    copyDeleteRow($chat, null, 901);
    copyDeleteRow($chat, COPY_OLD_CHAT, null);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    Http::assertNothingSent();
});

it('never calls a header missing its id or time', function (array $header) {
    copyDeleteTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create($header);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    Http::assertNothingSent();
})->with([
    'no message id' => [['forward_header_chat_id' => COPY_OLD_CHAT, 'forward_header_message_id' => null, 'forward_header_at' => null]],
    'no time' => [['forward_header_chat_id' => COPY_OLD_CHAT, 'forward_header_message_id' => 900, 'forward_header_at' => null]],
]);

it('uses the old stored pair after the target chat was switched', function () {
    copyDeleteTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create();
    copyDeleteRow($chat, COPY_OLD_CHAT, 901);

    telegramBotConfigured(['services.telegram.chats.inbox' => COPY_NEW_CHAT]);
    expect(InboxChat::id())->toBe(COPY_NEW_CHAT);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    expect(copyDeleteCalls())->toBe([['chat_id' => (string) COPY_OLD_CHAT, 'message_id' => 901]]);
    Http::assertNotSent(fn (Request $request) => $request['chat_id'] === COPY_NEW_CHAT);
});

it('takes nothing from the client: the screen call passes no chat or message id', function () {
    copyDeleteTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create();
    copyDeleteRow($chat, COPY_OLD_CHAT, 901);
    confirmAdminPassword();

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->call('deleteConversation', '-1009999999999', 555)
        ->assertRedirect(route('admin.telegram.chats'));

    expect(copyDeleteCalls())->toBe([['chat_id' => (string) COPY_OLD_CHAT, 'message_id' => 901]]);
});

it('makes no Telegram call on prune, even with stored young pairs', function () {
    copyDeleteTelegramOk();
    $stale = TelegramUser::factory()->registered()->create([
        'last_message_at' => now()->subMonths(13),
        'forward_header_chat_id' => COPY_OLD_CHAT,
        'forward_header_message_id' => 900,
        'forward_header_at' => now()->subHour(),
    ]);
    copyDeleteRow($stale, COPY_OLD_CHAT, 901);

    Artisan::call('model:prune', ['--model' => [TelegramUser::class]]);

    Http::assertNothingSent();
    expect(TelegramUser::query()->find($stale->id))->toBeNull();
});

it('logs counts only', function () {
    Log::spy();
    Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true, 'result' => true])->push(['ok' => false], 400)]);

    (new DeleteForwardedCopies([[COPY_OLD_CHAT, 901], [COPY_OLD_CHAT, 902]]))->handle(app(TelegramClient::class));

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
        return $context === ['deleted' => 1, 'failed' => 1]
            && ! str_contains($message.json_encode($context), '901')
            && ! str_contains($message.json_encode($context), (string) COPY_OLD_CHAT);
    });
});

it('changes nothing on a 400: no throw, no chat check, no lost_target, no alert', function () {
    Http::fake([
        'api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['type' => 'supergroup']]),
        'api.telegram.org/*/deleteMessage' => Http::response(['ok' => false, 'description' => 'message can\'t be deleted'], 400),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
    $client = app(TelegramClient::class);
    $target = (string) COPY_OLD_CHAT;
    expect(InboxChat::verify($client, $target))->toBeTrue();
    $sentBefore = Http::recorded()->count();
    $generation = InboxChat::generation();

    (new DeleteForwardedCopies([[COPY_OLD_CHAT, 901], [COPY_OLD_CHAT, 902], [COPY_OLD_CHAT, 903], [COPY_OLD_CHAT, 904]]))->handle($client);

    $urls = Http::recorded()->skip($sentBefore)->map(fn (array $pair) => basename($pair[0]->url()))->values()->all();
    expect($urls)->toBe(array_fill(0, 4, 'deleteMessage'))
        ->and(Cache::has('telegram-forward-alert:lost_target'))->toBeFalse()
        ->and(InboxChat::generation())->toBe($generation)
        ->and(InboxChat::verify($client, $target))->toBeTrue();

    expect(Http::recorded()->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/getChat'))->count())->toBe(1);
});

it('counts a server error without retrying and goes on to the next pair', function () {
    Log::spy();
    Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true, 'result' => true])]);

    (new DeleteForwardedCopies([[COPY_OLD_CHAT, 901], [COPY_OLD_CHAT, 902]]))->handle(app(TelegramClient::class));

    expect(copyDeleteCalls())->toHaveCount(2);
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context === ['deleted' => 1, 'failed' => 1]);
});

it('stops on a 429 and counts what is left as failed', function () {
    Log::spy();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 30]], 429)]);

    (new DeleteForwardedCopies([[COPY_OLD_CHAT, 901], [COPY_OLD_CHAT, 902]]))->handle(app(TelegramClient::class));

    expect(copyDeleteCalls())->toHaveCount(1);
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context === ['deleted' => 0, 'failed' => 2]);
});

it('stops at the deadline and counts what is left as failed', function () {
    Log::spy();
    Http::fake(function () {
        $this->travel(DeleteForwardedCopies::DEADLINE_SECONDS + 1)->seconds();

        return Http::response(['ok' => true, 'result' => true]);
    });

    (new DeleteForwardedCopies([[COPY_OLD_CHAT, 901], [COPY_OLD_CHAT, 902]]))->handle(app(TelegramClient::class));

    expect(copyDeleteCalls())->toHaveCount(1);
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context === ['deleted' => 1, 'failed' => 1]);
});

it('makes no call with the bot off or without a token', function (bool $botOn, bool $token) {
    copyDeleteTelegramOk();
    Log::spy();
    copyDeleteBotSwitch($botOn);

    if (! $token) {
        telegramBotConfigured(['services.telegram.bot_token' => null]);
    }

    (new DeleteForwardedCopies([[COPY_OLD_CHAT, 901]]))->handle(app(TelegramClient::class));

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context === ['skipped' => 1]);
})->with([
    'bot off' => [false, true],
    'no token' => [true, false],
]);

it('keeps the delete and raises nothing when the queue push fails after commit', function () {
    copyDeleteTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create();
    copyDeleteRow($chat, COPY_OLD_CHAT, 901);

    Queue::extend('copies-push-fails', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config): SyncQueue
        {
            return new class extends SyncQueue
            {
                public function push($job, $data = '', $queue = null)
                {
                    throw new RuntimeException('queue down');
                }
            };
        }
    });
    config(['queue.connections.copies-push-fails' => ['driver' => 'copies-push-fails'], 'queue.default' => 'copies-push-fails']);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    expect(TelegramUser::query()->find($chat->id))->toBeNull();
    Http::assertNothingSent();
});

it('stays out of the Monitoring retry list', function () {
    expect(config('monitoring.retry_classes'))->not->toContain(DeleteForwardedCopies::class);
});
