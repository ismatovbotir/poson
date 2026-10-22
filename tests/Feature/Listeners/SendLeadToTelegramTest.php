<?php

use App\Actions\Leads\CreateLead;
use App\Data\Leads\LeadData;
use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Enums\TrafficChannel;
use App\Events\LeadCreated;
use App\Listeners\SendLeadToTelegram;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use App\Support\Telegram\LeadMessage;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const ALERT_BOT_TOKEN = '123456789:AAAlertTokenAlertTokenAlertTokenAl42';

/**
 * @param  array<string, mixed>  $env  extra config values (as if from .env)
 */
function telegramConfigured(array $env = []): void
{
    freshSecrets(array_merge([
        'services.telegram.bot_token' => ALERT_BOT_TOKEN,
        'services.telegram.chats.sales' => '-1001111111111',
        'services.telegram.chats.service' => '-1002222222222',
    ], $env));
}

function runAlert(Lead $lead): SendLeadToTelegram
{
    $listener = app(SendLeadToTelegram::class)->withFakeQueueInteractions();
    $listener->handle(new LeadCreated($lead));

    return $listener;
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.url' => 'http://localhost']);

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
    });
});

afterEach(function () {
    expect(implode("\n", $this->logged))->not->toContain('AAAlertToken');
});

it('sends a sales lead to the sales chat and a service request to the service chat', function (LeadType $type, string $chat, string $chatId) {
    telegramConfigured();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
    $lead = Lead::factory()->state(['type' => $type])->create();

    $listener = runAlert($lead);

    $listener->assertNotFailed();
    $listener->assertNotReleased();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot'.ALERT_BOT_TOKEN.'/sendMessage'
        && $request['chat_id'] === $chatId
        && $request['parse_mode'] === 'HTML');

    expect($lead->activities()->sole())
        ->type->toBe(ActivityType::Notification)
        ->meta->toBe(['result' => 'sent', 'chat' => $chat]);
})->with([
    'callback' => [LeadType::Callback, 'sales', '-1001111111111'],
    'quote' => [LeadType::Quote, 'sales', '-1001111111111'],
    'service request' => [LeadType::ServiceRequest, 'service', '-1002222222222'],
]);

it('renders the message in Uzbek by default with the lead details and nothing private', function () {
    telegramConfigured();
    $original = Lead::factory()->create();
    $customer = Customer::factory()->create(['name' => 'Mega Savdo MChJ']);
    $lead = Lead::factory()->quote()->duplicateOf($original)->forCustomer($customer)->create([
        'name' => 'Aziz Karimov', 'phone' => '+998901234567', 'company' => 'OOO Market',
        'email' => 'aziz@example.com', 'inn' => '305123456', 'message' => 'Need 3 printers',
        'traffic_channel' => TrafficChannel::Search, 'traffic_source' => 'secret-campaign',
        'utm' => ['utm_source' => 'secret-campaign'], 'landing_path' => '/uz/hidden-landing', 'page_url' => 'https://poson.uz/uz/page-url',
    ]);
    LeadItem::factory()->for($lead)->forProduct(Product::factory()->create(['sku' => 'ZB-ZD421', 'name' => ['uz' => 'Zebra ZD421']]))->create(['qty' => 2]);

    $html = LeadMessage::for($lead->load(['items', 'customer']), TelegramLocale::current())->html;

    expect($html)
        ->toContain('<b>Yangi murojaat L-'.$lead->id.'</b>')
        ->toContain(LeadType::Quote->label())
        ->toContain('Zebra ZD421 (ZB-ZD421) ×2')
        ->toContain('Aziz Karimov · +998 (90) 123-45-67')
        ->toContain('OOO Market')
        ->toContain('Need 3 printers')
        ->toContain(TrafficChannel::Search->label())
        ->toContain('L-'.$original->id)
        ->toContain('Mega Savdo MChJ')
        ->not->toContain('aziz@example.com')
        ->not->toContain('305123456')
        ->not->toContain('secret-campaign')
        ->not->toContain('hidden-landing')
        ->not->toContain('page-url')
        ->not->toMatch('/\p{Cyrillic}/u');
});

it('uses the configured locale', function (string $locale, string $title) {
    telegramConfigured(['services.telegram.locale' => $locale]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    runAlert(Lead::factory()->create());

    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], $title));
})->with([
    'uz' => ['uz', 'Yangi murojaat'],
    'ru' => ['ru', 'Новая заявка'],
    'en' => ['en', 'New lead'],
]);

it('escapes malicious names and comments', function () {
    $lead = Lead::factory()->create([
        'name' => '<b>Boss</b><a href="https://evil.example">x</a>',
        'company' => 'A & B <script>',
        'message' => '</b><i>hi</i>',
    ])->load(['items', 'customer']);

    $html = LeadMessage::for($lead, 'en')->html;

    expect($html)
        ->toContain('&lt;b&gt;Boss&lt;/b&gt;&lt;a href=&quot;https://evil.example&quot;&gt;')
        ->toContain('A &amp; B &lt;script&gt;')
        ->toContain('&lt;/b&gt;&lt;i&gt;hi&lt;/i&gt;')
        ->not->toContain('<script>')
        ->not->toContain('<i>');
});

it('cuts the comment to a 300-character excerpt', function () {
    $lead = Lead::factory()->create(['message' => str_repeat('a', 280).' '.str_repeat('b', 200)])->load(['items', 'customer']);

    $html = LeadMessage::for($lead, 'en')->html;
    preg_match('/Comment:<\/b> (.*)/', $html, $comment);

    expect(mb_strlen($comment[1]))->toBeLessThanOrEqual(LeadMessage::COMMENT_EXCERPT + 1)
        ->and($comment[1])->toEndWith('…')
        ->and($html)->not->toContain(str_repeat('b', 30));
});

it('adds the admin button only when APP_URL is https', function () {
    $lead = Lead::factory()->create()->load(['items', 'customer']);

    expect(LeadMessage::for($lead, 'uz')->buttons)->toBe([]);

    // The request host is http://localhost: the URL must come from APP_URL alone.
    config(['app.url' => 'https://poson.uz']);

    expect(LeadMessage::for($lead, 'uz')->buttons)->toBe([['text' => 'Admin panelda ochish', 'url' => 'https://poson.uz/admin/leads/'.$lead->id]]);

    telegramConfigured();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    runAlert($lead);

    Http::assertSent(fn (Request $request): bool => $request['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://poson.uz/admin/leads/'.$lead->id);
});

it('releases the job for retry_after on 429', function () {
    telegramConfigured();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 17]], 429)]);
    $lead = Lead::factory()->create();

    runAlert($lead)->assertReleased(17);

    expect($lead->activities()->count())->toBe(0);
});

it('fails at once without retry on 400 and 403', function (int $status) {
    telegramConfigured();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => $status], $status)]);

    $listener = runAlert(Lead::factory()->create());

    $listener->assertFailedWith(TelegramException::class);
    $listener->assertNotReleased();
})->with([400, 401, 403, 404]);

it('rethrows server errors and timeouts so the queue retries with backoff', function (Closure $fake) {
    telegramConfigured();
    Http::fake($fake());

    expect(fn () => runAlert(Lead::factory()->create()))->toThrow(TelegramException::class);

    $listener = app(SendLeadToTelegram::class);

    expect($listener->tries)->toBe(5)
        ->and($listener->backoff())->toBe([30, 120, 600, 1800]);
})->with([
    '5xx' => fn () => fn () => ['api.telegram.org/*' => Http::response(['ok' => false], 502)],
    'timeout' => fn () => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://api.telegram.org/bot'.ALERT_BOT_TOKEN.'/sendMessage'),
]);

it('records a failed activity with the code in failed()', function () {
    $lead = Lead::factory()->serviceRequest()->create();

    app(SendLeadToTelegram::class)->failed(new LeadCreated($lead), new TelegramException(403));

    expect($lead->activities()->sole())
        ->type->toBe(ActivityType::Notification)
        ->meta->toBe(['result' => 'failed', 'chat' => 'service', 'code' => '403']);
});

it('sends nothing when the token or the chat is empty or alerts are off', function (array $env) {
    freshSecrets($env);
    Http::fake();
    $lead = Lead::factory()->create();

    runAlert($lead)->assertNotFailed();

    Http::assertNothingSent();
    expect($lead->activities()->count())->toBe(0);
})->with([
    'no token' => [['services.telegram.chats.sales' => '-100111']],
    'no chat' => [['services.telegram.bot_token' => ALERT_BOT_TOKEN, 'services.telegram.chats.service' => '-100222']],
    'alerts off' => [['services.telegram.bot_token' => ALERT_BOT_TOKEN, 'services.telegram.chats.sales' => '-100111', 'services.telegram.notify_new_leads' => 'false']],
]);

it('never puts the token in exception messages', function () {
    telegramConfigured();
    Http::fake(fn () => throw new ConnectionException('Could not resolve host for https://api.telegram.org/bot'.ALERT_BOT_TOKEN.'/sendMessage'));

    try {
        runAlert(Lead::factory()->create());
        $this->fail('Expected a TelegramException.');
    } catch (TelegramException $exception) {
        expect($exception->getMessage())->not->toContain('AAAlertToken')->not->toContain('api.telegram.org')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->code())->toBe('connection');
    }
});

it('listens to LeadCreated', function () {
    Event::fake();

    Event::assertListening(LeadCreated::class, SendLeadToTelegram::class);
});

it('queues the listener only after the lead transaction commits', function () {
    Queue::fake();

    $data = new LeadData(source: LeadSource::Site, type: LeadType::Callback, name: 'A', phone: '+998901234567', locale: 'uz');

    try {
        DB::transaction(function () use ($data): void {
            app(CreateLead::class)->handle($data);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(CallQueuedListener::class);

    app(CreateLead::class)->handle($data);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SendLeadToTelegram::class);
});

it('shows the notification entries in the lead history in every locale', function (string $locale, string $sent, string $failed) {
    app()->setLocale($locale);
    $admin = staffUser();
    $lead = Lead::factory()->create();
    $lead->activities()->create(['type' => ActivityType::Notification, 'meta' => ['result' => 'sent', 'chat' => 'sales'], 'created_at' => now()]);
    $lead->activities()->create(['type' => ActivityType::Notification, 'meta' => ['result' => 'failed', 'chat' => 'service', 'code' => '403'], 'created_at' => now()]);

    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])
        ->assertSee($sent)
        ->assertSee($failed);
})->with([
    'uz' => ['uz', 'Telegramga yuborildi', 'Telegramga yuborib boʻlmadi'],
    'ru' => ['ru', 'Отправлено в Telegram', 'Не удалось отправить в Telegram'],
    'en' => ['en', 'Sent to Telegram', 'send to Telegram'],
]);

it('sends the alert end to end when a lead is created (sync queue)', function () {
    telegramConfigured();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $lead = app(CreateLead::class)->handle(new LeadData(source: LeadSource::Site, type: LeadType::ServiceRequest, name: 'Zarina', phone: '+998998887766', locale: 'ru'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '-1002222222222' && str_contains($request['text'], 'Zarina'));

    expect($lead->activities()->where('type', ActivityType::Notification)->sole()->meta)->toBe(['result' => 'sent', 'chat' => 'service']);
});

it('restores the app locale after rendering', function () {
    app()->setLocale('ru');

    LeadMessage::for(Lead::factory()->create()->load(['items', 'customer']), 'uz');

    expect(app()->getLocale())->toBe('ru');
});

it('sends nothing when alerts are switched off in the admin', function () {
    Setting::factory()->create(['key' => 'telegram.notify_new_leads', 'group' => 'integrations', 'value' => false]);
    telegramConfigured();
    Http::fake();

    expect(Secrets::get('telegram.notify_new_leads'))->toBeFalse();

    runAlert(Lead::factory()->create());

    Http::assertNothingSent();
});

it('ignores an invalid .env value for the alerts toggle and uses the admin value or the default', function () {
    Exceptions::fake();
    telegramConfigured(['services.telegram.notify_new_leads' => 'maybe']);

    expect(Secrets::get('telegram.notify_new_leads'))->toBeTrue();

    Setting::factory()->create(['key' => 'telegram.notify_new_leads', 'group' => 'integrations', 'value' => false]);
    Secrets::flush();

    expect(Secrets::get('telegram.notify_new_leads'))->toBeFalse();
});

it('never breaks an entity or a multibyte character at the excerpt cut', function (string $tail) {
    $message = str_repeat('x', LeadMessage::COMMENT_EXCERPT - 2).$tail.str_repeat('y', 50);
    $lead = Lead::factory()->create(['message' => $message])->load(['items', 'customer']);

    preg_match('/Comment:<\/b> (.*)/', LeadMessage::for($lead, 'en')->html, $comment);
    $excerpt = $comment[1];

    expect(mb_check_encoding($excerpt, 'UTF-8'))->toBeTrue()
        ->and(preg_match('/&(?!(amp|lt|gt|quot|#039);)/', $excerpt))->toBe(0)
        ->and(html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8'))->toStartWith(mb_substr($message, 0, LeadMessage::COMMENT_EXCERPT - 2));
})->with([
    'ampersands' => ['&&&&'],
    'angle brackets' => ['<<>>'],
    'Uzbek and Cyrillic letters' => ['oʻgʻЖЁ'],
]);

it('records code "error" when the attempts run out on another exception', function () {
    $lead = Lead::factory()->create();

    app(SendLeadToTelegram::class)->failed(new LeadCreated($lead), new MaxAttemptsExceededException('max attempts'));

    expect($lead->activities()->sole()->meta)->toBe(['result' => 'failed', 'chat' => 'sales', 'code' => 'error']);
});

it('does not resend when writing the history fails after a successful send', function () {
    Exceptions::fake();
    telegramConfigured();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    Activity::creating(fn () => throw new RuntimeException('history table down'));

    runAlert(Lead::factory()->create())->assertNotFailed();

    Http::assertSentCount(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('drops the job when the lead was deleted', function () {
    expect(app(SendLeadToTelegram::class)->deleteWhenMissingModels)->toBeTrue();
});

describe('dashboard warning card', function () {
    it('shows failed alerts of the last 7 days to the admin with links to the latest leads', function () {
        $admin = staffUser();
        $leads = Lead::factory()->count(7)->create();

        foreach ($leads as $lead) {
            $lead->activities()->create(['type' => ActivityType::Notification, 'meta' => ['result' => 'failed', 'chat' => 'sales', 'code' => '403'], 'created_at' => now()->subDay()]);
        }

        $old = Lead::factory()->create();
        $old->activities()->create(['type' => ActivityType::Notification, 'meta' => ['result' => 'failed', 'chat' => 'sales', 'code' => '403'], 'created_at' => now()->subDays(8)]);
        $old->activities()->create(['type' => ActivityType::Notification, 'meta' => ['result' => 'sent', 'chat' => 'sales'], 'created_at' => now()]);

        $component = Livewire::actingAs($admin)->test(Dashboard::class)
            ->assertSeeHtml('data-telegram-failures')
            ->assertSee(__('crm.dashboard.telegram_failures.body', ['count' => 7]))
            ->assertSee($leads->last()->reference());

        // The latest-leads list also shows references, so check the card's own data.
        expect($component->get('telegramFailures')['count'])->toBe(7)
            ->and($component->get('telegramFailures')['leads']->pluck('id')->all())->not->toContain($old->id)
            ->and($component->get('telegramFailures')['leads'])->toHaveCount(5)
            ->and($component->get('telegramFailures')['leads']->first()->id)->toBe($leads->last()->id);
    });

    it('is hidden without failures and from other roles', function () {
        $lead = Lead::factory()->create();

        Livewire::actingAs(staffUser())->test(Dashboard::class)->assertDontSeeHtml('data-telegram-failures');

        $lead->activities()->create(['type' => ActivityType::Notification, 'meta' => ['result' => 'failed', 'chat' => 'sales', 'code' => '403'], 'created_at' => now()]);

        Livewire::actingAs(User::factory()->withRole(Role::SalesManager)->create())->test(Dashboard::class)
            ->assertDontSeeHtml('data-telegram-failures');
    });
});
