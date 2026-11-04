<?php

use App\Actions\Monitoring\MonitoringActionException;
use App\Actions\Monitoring\RetryFailedJobs;
use App\Actions\Telegram\CheckTelegramWebhook;
use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\RetryForward;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Jobs\SendTelegramMessage;
use App\Listeners\SendTelegramInboxAlert;
use App\Livewire\Admin\System\Monitoring;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\MonitoringAction;
use App\Models\MonitoringState;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\InboxChat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Plan 21 A6 / B1 / B3 abuse cases (T21-9, T21-20, T21-29, T21-30, amended
 * TG-27): the inbox alert job and listener, the media forward job, the status
 * check, the Show screen and the retention lines. Happy paths live in
 * tests/Feature/Telegram/TelegramInboxTest.php and TelegramRetentionTest.php;
 * only the hostile angles are here. Bot API faked (fake token), queue sync.
 *
 * Not written, by design: banned rows in prune and the 30-day unregistered
 * rule (they wait for the owner's decision), and the sendMessage 403 with a
 * cached pass (covered by "drops the cached chat verdict on a 400 / 403 from
 * the inbox alert send" in TelegramInboxTest). There is no admin delete or
 * deleteMessage code on this branch, so the "null chat id pair is never a
 * delete target" case has nothing to exercise yet.
 */
const ARET_SALES = '-1001111111111';

const ARET_INBOX = '-1002222222222';

const ARET_FINANCE = '-1003333333333';

const ARET_USER = 5580001;

const ARET_PRUNE_TELEGRAM = 'model:prune --model=App\\Models\\TelegramUser --model=App\\Models\\TelegramUpdate';

const ARET_PRUNE_FAILED = 'queue:prune-failed --hours=720';

function aretBotOn(): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
}

/**
 * Fake Bot API by method (an array is the ok body, an int an error status, a
 * closure returns either); calling it again only swaps the answers.
 *
 * @param  array<string, mixed>  $responses
 */
function aretApi(array $responses = []): void
{
    $faked = app()->bound('aret.api');
    app()->instance('aret.api', $responses);

    if ($faked) {
        return;
    }

    Http::fake(['api.telegram.org/*' => function (Request $request) {
        $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $response = app('aret.api')[$method] ?? match ($method) {
            'getChat' => ['id' => (int) ($request->data()['chat_id'] ?? 0), 'type' => 'supergroup', 'title' => 'Team'],
            'sendMessage' => ['message_id' => 900],
            'copyMessage' => ['message_id' => 901],
            'getWebhookInfo' => ['url' => (string) ConfigureTelegramWebhook::expectedUrl(), 'pending_update_count' => 0],
            default => true,
        };

        if ($response instanceof Closure) {
            $response = $response($request);
        }

        if (is_int($response)) {
            return Http::response(['ok' => false, 'error_code' => $response, 'description' => 'Bad Request: secret description', 'parameters' => ['retry_after' => 7]], $response);
        }

        return Http::response(['ok' => true, 'result' => $response]);
    }]);
}

/**
 * An answer that fails like the given kind: an HTTP status, or "timeout".
 */
function aretFailure(int|string $kind): int|Closure
{
    return $kind === 'timeout'
        ? function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
        }
    : $kind;
}

/**
 * @return list<array{method: string, data: array<string, mixed>}>
 */
function aretCalls(?string $method = null, ?string $chatId = null): array
{
    return collect(telegramApiCalls())
        ->filter(fn (array $call) => $method === null || $call['method'] === $method)
        ->filter(fn (array $call) => $chatId === null || (string) ($call['data']['chat_id'] ?? '') === $chatId)
        ->values()->all();
}

/**
 * The management-chat alert texts so far.
 *
 * @return list<string>
 */
function aretAlertTexts(): array
{
    return collect(aretCalls('sendMessage', TG_MANAGEMENT_CHAT))->pluck('data.text')->values()->all();
}

function aretReason(string $reason): string
{
    return e(__('telegram.monitoring.forward_reasons.'.$reason, [], 'uz'));
}

function aretUser(): TelegramUser
{
    return TelegramUser::factory()->registered()->create(['telegram_id' => ARET_USER, 'name' => 'Ali Valiyev', 'locale' => 'en']);
}

/**
 * An inbound, pending photo row of a conversation (what the intake would store).
 */
function aretMedia(TelegramUser $user, int $messageId = 50): TelegramMessage
{
    return TelegramMessage::factory()->media(TelegramMessageKind::Photo)->create([
        'telegram_user_id' => $user->id, 'direction' => TelegramDirection::In, 'telegram_message_id' => $messageId,
    ]);
}

/**
 * Run the forward job like a worker would (middleware included); a Telegram
 * error is swallowed (the worker retries).
 */
function aretRunForward(TelegramMessage $row): ForwardTelegramMedia
{
    $job = (new ForwardTelegramMedia($row->id, $row->telegram_user_id))->withFakeQueueInteractions();

    try {
        $job->middleware()[0]->handle($job, fn (ForwardTelegramMedia $job) => $job->handle(app(TelegramClient::class)));
    } catch (TelegramException) {
        // retried by the worker
    }

    return $job;
}

function aretInboxJob(string $chat = 'sales'): SendTelegramMessage
{
    return (new SendTelegramMessage($chat, 'alert text SECRET', [], 'TelegramInboxMessage', inbox: true))->withFakeQueueInteractions();
}

/**
 * A pending customer text row whose alert slot (last_alert_at) the message owns.
 */
function aretTextMessage(TelegramUser $user): TelegramMessage
{
    $user->forceFill(['last_alert_at' => now()])->save();

    return TelegramMessage::factory()->create(['telegram_user_id' => $user->id, 'text' => 'customer SECRET text']);
}

function aretFailedJob(string $class, mixed $failedAt): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => $class, 'data' => ['commandName' => $class, 'command' => 'O:1:"x":0:{}']]),
        'exception' => 'RuntimeException: Telegram API error (HTTP 500)',
        'failed_at' => $failedAt,
    ]);

    return $uuid;
}

/**
 * The queue payload of a job as it sat in failed_jobs before the deploy that
 * added the inbox flag: SendTelegramMessage serializes without it.
 */
function aretFailedSendJob(string $chat): string
{
    $uuid = (string) Str::uuid();
    $class = SendTelegramMessage::class;

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $uuid, 'displayName' => $class, 'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 5, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => '30,120,600,1800', 'timeout' => null, 'retryUntil' => null,
            'data' => ['commandName' => $class, 'command' => serialize(new SendTelegramMessage($chat, 'old payload', [], 'OrderPaid'))],
            'attempts' => 5,
        ]),
        'exception' => 'RuntimeException: Telegram API error (HTTP 500)',
        'failed_at' => now(),
    ]);

    return $uuid;
}

/**
 * The Monitoring screen for an Admin with a fresh password confirmation and
 * the schedule snapshot the screen reads.
 */
function aretMonitoring(): array
{
    Artisan::call('schedule:list');
    MonitoredTasks::writeSnapshot();
    $admin = staffUser();
    confirmAdminPassword();

    return [$admin, Livewire::actingAs($admin)->test(Monitoring::class)];
}

function aretPrune(string ...$models): void
{
    Artisan::call('model:prune', ['--model' => $models]);
}

beforeEach(function () {
    SymfonyRequest::setTrustedHosts([]);
    telegramBotConfigured();
    aretBotOn();
    aretApi();
});

afterEach(function () {
    SymfonyRequest::setTrustedHosts([]);
});

/* ----------------------------------------------- A6: the inbox alert job */

// 1. Payloads queued before the deploy have no inbox flag and are retried from failed_jobs.
it('sends an old-format payload without inbox to the sales or finance chat, also when retried from failed jobs', function (string $chat, string $chatId) {
    telegramBotConfigured(['services.telegram.chats.finance' => ARET_FINANCE]);
    $payload = serialize(new SendTelegramMessage($chat, 'old payload', [], 'OrderPaid'));

    expect($payload)->not->toContain('inbox');

    unserialize($payload)->handle(app(TelegramClient::class));

    expect(aretCalls('sendMessage', $chatId))->toHaveCount(1)
        ->and(aretCalls('getChat'))->toBe([]);

    $uuid = aretFailedSendJob($chat);
    $admin = staffUser();
    confirmAdminPassword();

    expect(app(RetryFailedJobs::class)->handle($admin, [$uuid]))->toBe(1);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);

    expect(aretCalls('sendMessage', $chatId))->toHaveCount(2)
        ->and(aretCalls('getChat'))->toBe([])
        ->and(aretCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'sales' => ['sales', ARET_SALES],
    'finance' => ['finance', ARET_FINANCE],
]);

// 2. lost_target and refused_target are separate dedupe keys.
it('lets refused_target through after a false lost_target alert used its own dedupe, and copies nothing', function (mixed $nowLost) {
    aretApi(['sendMessage' => fn (Request $request) => (string) $request->data()['chat_id'] === ARET_SALES ? 400 : ['message_id' => 1]]);
    $user = aretUser();

    // A 400 on the header while the chat is fine: the alert is raised and the dedupe slot is taken.
    aretRunForward(aretMedia($user, 50));

    expect(aretAlertTexts())->toHaveCount(1)->and(aretAlertTexts()[0])->toContain(aretReason('lost_target'))
        ->and(Cache::has('telegram-forward-alert:lost_target'))->toBeTrue();

    // Now the chat is really lost (gone, or turned public).
    aretApi(['getChat' => $nowLost]);
    $next = aretMedia($user, 51);
    aretRunForward($next);

    $alerts = aretAlertTexts();

    expect($alerts)->toHaveCount(2)
        ->and($alerts[1])->toContain(aretReason('refused_target'))
        ->and($next->fresh()->forward_status)->toBe(TelegramForwardStatus::NotConfigured)
        ->and(aretCalls('copyMessage'))->toBe([])
        ->and(aretCalls('sendMessage', ARET_SALES))->toHaveCount(1);
})->with([
    'chat gone' => [400],
    'chat turned public' => [['id' => -1, 'type' => 'supergroup', 'username' => 'went_public']],
]);

// 3. The target is resolved when the job runs; a bad inbox value must not fall back to sales.
it('refuses an @username or malformed inbox id at send time while sales is valid: no getChat, nothing sent, one alert', function (string $inbox) {
    telegramBotConfigured(['services.telegram.chats.inbox' => $inbox]);

    foreach ([1, 2] as $_) {
        aretInboxJob()->handle(app(TelegramClient::class));
    }

    $alerts = aretAlertTexts();

    expect(aretCalls('getChat'))->toBe([])
        ->and(aretCalls('sendMessage', ARET_SALES))->toBe([])
        ->and(aretCalls('sendMessage', $inbox))->toBe([])
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0])->toContain(aretReason('refused_target'))
        ->and($alerts[0])->not->toContain('SECRET');
})->with([
    '@username' => '@poson_inbox',
    'no minus sign' => '1002222222222',
    'letters' => '-100222abc',
    'leading zero' => '-0123456',
    '21 digits' => '-100222222222222222222',
    'words' => 'team chat',
]);

// 4. A check that cannot answer now must neither send nor remember anything.
it('keeps a 429, 5xx or timeout from getChat out of the inbox job: nothing sent or cached, 429 released, others rethrown', function (int|string $kind) {
    aretApi(['getChat' => aretFailure($kind)]);
    $job = aretInboxJob();

    if ($kind === 429) {
        $job->handle(app(TelegramClient::class));
        $job->assertReleased(delay: 7);
    } else {
        expect(fn () => $job->handle(app(TelegramClient::class)))->toThrow(TelegramException::class);
        $job->assertNotReleased();
    }

    $job->assertNotFailed();

    expect(aretCalls('sendMessage'))->toBe([])
        ->and(Cache::has('telegram-forward-alert:refused_target'))->toBeFalse();

    // Nothing was cached as a verdict: a healthy retry asks Telegram again and sends.
    aretApi(['getChat' => null]);
    aretInboxJob()->handle(app(TelegramClient::class));

    // A timeout leaves no recorded response, so only the healthy call shows up for it.
    expect(aretCalls('getChat'))->toHaveCount($kind === 'timeout' ? 1 : 2)
        ->and(aretCalls('sendMessage', ARET_SALES))->toHaveCount(1)
        ->and(aretCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([]);
})->with([429, 502, 'timeout']);

// 5. A refused token is not a verdict on the chat.
it('fails the inbox job once on a getChat 401 or 404 without refused_target or a cached flag', function (int $status) {
    aretApi(['getChat' => $status]);
    $job = aretInboxJob();

    $job->handle(app(TelegramClient::class));

    $job->assertFailed();
    $job->assertNotReleased();

    expect(aretCalls('getChat'))->toHaveCount(1)
        ->and(aretCalls('sendMessage'))->toBe([])
        ->and(Cache::has('telegram-forward-alert:refused_target'))->toBeFalse();

    // The token is fixed: no cached "false" is waiting.
    aretApi(['getChat' => null]);
    aretInboxJob()->handle(app(TelegramClient::class));

    expect(aretCalls('getChat'))->toHaveCount(2)
        ->and(aretCalls('sendMessage', ARET_SALES))->toHaveCount(1);
})->with([401, 404]);

// 6. Not written: the sendMessage 403 with a cached pass is covered by "drops the cached chat
// verdict on a 400 / 403 from the inbox alert send, but not for other alerts (T21-30)" in
// tests/Feature/Telegram/TelegramInboxTest.php (inbox 403: second job asks getChat again).

// 7. The listener (the event asks getChat) on a refused token.
it('lets the listener retry a getChat 401 through every try with nothing sent, cached or alerted, and gives the slot back', function () {
    $user = aretUser();
    $message = aretTextMessage($user);
    $event = new TelegramInboxMessage($message->id);
    $listener = app(SendTelegramInboxAlert::class);
    aretApi(['getChat' => 401]);

    for ($try = 1; $try <= $listener->tries; $try++) {
        expect(fn () => $listener->handle($event))->toThrow(TelegramException::class);
    }

    expect(aretCalls('getChat'))->toHaveCount($listener->tries)
        ->and(aretCalls('sendMessage'))->toBe([])
        ->and(Cache::has('telegram-forward-alert:refused_target'))->toBeFalse()
        ->and($user->fresh()->last_alert_at)->not->toBeNull();

    $listener->failed($event, new TelegramException(401));

    expect($user->fresh()->last_alert_at)->toBeNull();

    // The token is fixed: nothing bad was cached, so the next alert is sent.
    aretApi(['getChat' => null]);
    $listener->handle($event);

    expect(aretCalls('sendMessage', ARET_SALES))->toHaveCount(1)
        ->and(aretCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([]);
});

/* ------------------------------------------- A6: the media forward job */

// 8. A bad token fails files; it never reads as a lost chat, and staff can retry after the fix.
it('fails files on a 401 at the header and at the copy with a cached pass: no invalidate, no alert; a staff retry after the fix forwards', function () {
    $user = aretUser();
    aretRunForward(aretMedia($user, 50));
    expect(TelegramMessage::query()->where('telegram_message_id', 50)->sole()->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and(aretCalls('getChat'))->toHaveCount(1);

    // The header of the first file is old enough to be sent again.
    TelegramUser::query()->update(['forward_header_message_id' => null, 'forward_header_at' => null, 'forward_header_chat_id' => null]);
    aretApi(['sendMessage' => 401]);
    $onHeader = aretMedia($user, 51);
    aretRunForward($onHeader);

    aretApi(['copyMessage' => 401]);
    $onCopy = aretMedia($user, 52);
    aretRunForward($onCopy);

    expect($onHeader->fresh()->forward_status)->toBe(TelegramForwardStatus::Failed)
        ->and($onHeader->fresh()->forward_error_code)->toBe(401)
        ->and($onCopy->fresh()->forward_status)->toBe(TelegramForwardStatus::Failed)
        ->and($onCopy->fresh()->forward_error_code)->toBe(401)
        // The cached pass survived both: the chat was never asked about again.
        ->and(aretCalls('getChat'))->toHaveCount(1)
        ->and(aretCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([])
        ->and(Cache::has('telegram-forward-alert:lost_target'))->toBeFalse()
        ->and(Cache::has('telegram-forward-alert:refused_target'))->toBeFalse();

    // The token is fixed in .env / Settings; staff retry both files.
    aretApi();
    $admin = staffUser(Role::Admin);

    expect(app(RetryForward::class)->handle($admin, $onHeader->fresh()))->toBeTrue()
        ->and(app(RetryForward::class)->handle($admin, $onCopy->fresh()))->toBeTrue()
        ->and($onHeader->fresh()->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and($onCopy->fresh()->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and($onCopy->fresh()->forwarded_chat_id)->toBe((int) ARET_SALES);
});

// 9. The check runs before any cap is spent.
it('leaves the counters and the cap marker untouched when the check phase gets a 401 or 404', function (int $status) {
    $user = aretUser();
    $row = aretMedia($user);
    RateLimiter::hit(ForwardTelegramMedia::conversationKey($user->id), 3600);
    RateLimiter::hit(ForwardTelegramMedia::DAILY_KEY, 86400);
    RateLimiter::hit(ForwardTelegramMedia::DAILY_KEY, 86400);
    aretApi(['getChat' => $status]);

    aretRunForward($row);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Failed)
        ->and($row->fresh()->forward_error_code)->toBe($status)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::conversationKey($user->id)))->toBe(1)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::DAILY_KEY))->toBe(2)
        ->and(Cache::has(ForwardTelegramMedia::capDecisionKey($row->id)))->toBeFalse()
        ->and(aretCalls('sendMessage'))->toBe([])
        ->and(aretCalls('copyMessage'))->toBe([]);
})->with([401, 404]);

/* ------------------------------------------------ E: the status check */

// 10. The chat verdict is secondary; a refused token there is not the chat's fault.
it('reads webhook info ok but getChat 401 or 404 as an unknown chat: no exception, same result, no cached flag', function (int $status) {
    aretApi(['getChat' => $status]);

    expect(app(CheckTelegramWebhook::class)->handle())->toBe(CheckTelegramWebhook::OK);

    $meta = MonitoringState::query()->find(CheckTelegramWebhook::SNAPSHOT_KEY)->meta;

    expect($meta['chat'])->toBe('unknown')
        ->and($meta['get_me'])->toBe('ok')
        ->and($meta['code'])->toBeNull()
        ->and(aretCalls('getChat'))->toHaveCount(1)
        ->and(aretCalls('sendMessage'))->toBe([]);

    aretApi(['getChat' => null]);

    expect(InboxChat::verify(app(TelegramClient::class), ARET_SALES))->toBeTrue()
        ->and(aretCalls('getChat'))->toHaveCount(2);
})->with([401, 404]);

/* ----------------------------------------------- A1: the Show screen */

// 11. The chat ids of the staff-chat copies are internals.
it('never puts forwarded_chat_id or forward_header_chat_id in the Show HTML or snapshot', function () {
    $user = TelegramUser::factory()->registered()->create([
        'forward_header_message_id' => 424242, 'forward_header_at' => now(), 'forward_header_chat_id' => -1009876543210,
    ]);
    $message = TelegramMessage::factory()->for($user)->media(TelegramMessageKind::Photo)->forwarded()->create();
    TelegramMessage::query()->whereKey($message->id)->update(['forwarded_chat_id' => -1001234567899, 'forwarded_message_id' => 556677]);

    $show = Livewire::actingAs(staffUser())->test(Show::class, ['telegramUser' => $user])->call('loadOlder')->call('refreshThread');
    $blob = $show->html().json_encode($show->snapshot);

    foreach (['1009876543210', '1001234567899', '424242', '556677', 'forwarded_chat_id', 'forward_header_chat_id', 'forward_header_message_id'] as $internal) {
        expect($blob)->not->toContain($internal);
    }
});

// 12. Not written: no admin delete and no deleteMessage code exist on this branch (Show is
// read-only), so there is no delete target to select. Add it with the delete feature (TG-27 b).

/* --------------------------------------------------------- B3: retention */

// 13. Retention removes the conversation, never the CRM records it points at.
it('keeps the customer and contact of a pruned thread that has no lead', function (string $branch) {
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->create(['customer_id' => $customer->id]);
    $thread = $branch === 'unregistered'
        ? TelegramUser::factory()->unregistered()->create(['created_at' => now()->subDays(31)])
        : TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(13)]);
    $thread->forceFill(['customer_id' => $customer->id, 'contact_id' => $contact->id])->save();
    TelegramMessage::factory()->create(['telegram_user_id' => $thread->id]);

    aretPrune(TelegramUser::class);

    expect(TelegramUser::query()->whereKey($thread->id)->exists())->toBeFalse()
        ->and(TelegramMessage::query()->count())->toBe(0)
        ->and(Customer::query()->whereKey($customer->id)->exists())->toBeTrue()
        ->and(Contact::query()->whereKey($contact->id)->exists())->toBeTrue()
        ->and(Contact::query()->find($contact->id)->customer_id)->toBe($customer->id);
})->with(['unregistered', 'silent']);

// 14. One lead-linked message keeps the whole thread, not only that message.
it('keeps a thread whole when one of many messages is linked to a lead', function (string $branch) {
    $make = fn () => $branch === 'unregistered'
        ? TelegramUser::factory()->unregistered()->create(['created_at' => now()->subDays(40)])
        : TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(14)]);
    $kept = $make();
    $control = $make();
    TelegramMessage::factory()->count(5)->create(['telegram_user_id' => $kept->id]);
    TelegramMessage::factory()->count(5)->create(['telegram_user_id' => $control->id]);
    $lead = Lead::factory()->create();
    TelegramMessage::query()->where('telegram_user_id', $kept->id)->orderBy('id')->skip(2)->first()->forceFill(['lead_id' => $lead->id])->save();

    aretPrune(TelegramUser::class);

    expect(TelegramUser::query()->whereKey($control->id)->exists())->toBeFalse()
        ->and(TelegramUser::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(TelegramMessage::query()->where('telegram_user_id', $kept->id)->count())->toBe(5)
        ->and(TelegramMessage::query()->where('telegram_user_id', $control->id)->count())->toBe(0)
        ->and(Lead::query()->whereKey($lead->id)->exists())->toBeTrue();
})->with(['unregistered', 'silent']);

// 15. prune-failed is global; the monitoring screen can neither undo it nor trigger it.
it('prunes old failed jobs of every class, and a monitoring retry of a pruned uuid is refused as invalid', function () {
    $oldTelegram = aretFailedJob(SendTelegramMessage::class, now()->subDays(31));
    $oldOther = aretFailedJob('App\Jobs\SubmitIndexNow', now()->subDays(45));
    $recent = aretFailedJob(SendTelegramMessage::class, now()->subDays(29));
    $admin = staffUser();
    confirmAdminPassword();

    Artisan::call('queue:prune-failed', ['--hours' => 720]);

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$recent]);

    foreach ([$oldTelegram, $oldOther] as $uuid) {
        try {
            app(RetryFailedJobs::class)->handle($admin, [$uuid]);
            $reason = null;
        } catch (MonitoringActionException $exception) {
            $reason = $exception->reason;
        }

        expect($reason)->toBe('invalid');
    }

    $screen = Livewire::actingAs($admin)->test(Monitoring::class)->call('retryOne', $oldTelegram);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(MonitoringAction::query()->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
    $screen->assertDispatched('toast');
});

it('refuses Run now for the Telegram prune lines by a direct Livewire call', function (string $key) {
    Queue::fake();
    [$admin, $screen] = aretMonitoring();

    // The lines really are in the schedule, so the refusal is the allow-list, not a missing task.
    expect(MonitoredTasks::events()->has($key))->toBeTrue()
        ->and(MonitoredTasks::isManual($key))->toBeFalse();

    $screen->call('runNow', $key)->assertDispatched('toast');

    Queue::assertNothingPushed();
    expect(MonitoringAction::query()->count())->toBe(0)
        ->and(Cache::has('monitoring:run:'.$key))->toBeFalse();
})->with([
    'queue:prune-failed' => ARET_PRUNE_FAILED,
    'model:prune Telegram' => ARET_PRUNE_TELEGRAM,
]);
