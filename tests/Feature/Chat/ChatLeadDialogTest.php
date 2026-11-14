<?php

require_once __DIR__.'/ChatBotHelpers.php';

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\ChatAction;
use App\Enums\ChatDialogStep;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Events\LeadCreated;
use App\Models\ChatUnmatched;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Support\Chat\LeadDialog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C4: the three Telegram lead dialogs (CB-8, CB-17): one lead per
 * dialog with source and type from the server, the registered phone, cancel
 * and expiry, answer cleaning and caps, the server-side state, limits hit
 * last, and a double tap or a Telegram retry creating nothing twice.
 */
const CB_FLOWS = [
    'callback' => ['callback', LeadType::Callback],
    'quote' => ['quote', LeadType::Quote],
    'service' => ['servicerequest', LeadType::ServiceRequest],
];

beforeEach(function () {
    cbSetup();
    cbRule('service_request', ['servicerequest'], ['action' => ChatAction::LeadDialog, 'params' => ['flow' => 'service']]);
});

/**
 * Start a dialog by typing its keyword; returns the telegram user.
 */
function cbStartDialog(string $keyword, ?TelegramUser $user = null): TelegramUser
{
    $user ??= cbUser();
    cbText($keyword, $user->telegram_id);

    return $user;
}

/**
 * Type the details and press "Send"; returns the user.
 */
function cbFinishDialog(TelegramUser $user, string $details = 'Need 3 scanners for a warehouse'): void
{
    cbText($details, $user->telegram_id);
    cbPress(cbButton($user, 'send'), $user->telegram_id);
}

it('creates exactly one lead per flow with the server-chosen source and type', function (string $keyword, LeadType $type) {
    Event::fake([LeadCreated::class]);
    $user = cbUser();
    // A customer with the same phone must not be attached by phone matching.
    Customer::factory()->create(['phone' => '+998901234567']);

    cbStartDialog($keyword, $user);
    cbFinishDialog($user);

    $lead = Lead::query()->sole();

    expect($lead->source)->toBe(LeadSource::Telegram)
        ->and($lead->type)->toBe($type)
        ->and($lead->phone)->toBe('+998901234567')
        ->and($lead->message)->toBe('Need 3 scanners for a warehouse')
        ->and($lead->customer_id)->toBeNull()
        ->and($user->fresh()->bot_dialog['flow'] ?? null)->toBeNull()
        ->and(cbLast())->toContain('L-'.$lead->id);
    Event::assertDispatchedTimes(LeadCreated::class, 1);
})->with(CB_FLOWS);

it('maps every flow to its lead type on the server', function () {
    expect(LeadFlow::Callback->leadType())->toBe(LeadType::Callback)
        ->and(LeadFlow::Quote->leadType())->toBe(LeadType::Quote)
        ->and(LeadFlow::Service->leadType())->toBe(LeadType::ServiceRequest);
});

it('lets a callback skip the details but requires them for a quote and a service request', function () {
    $user = cbStartDialog('callback');
    cbPress(cbButton($user, 'skip'));
    cbPress(cbButton($user, 'send'));

    expect(Lead::query()->sole()->message)->toBeNull();

    foreach (['quote' => 11, 'servicerequest' => 12] as $keyword => $offset) {
        $other = cbStartDialog($keyword, cbUser(['phone' => '+99890111000'.$offset], CB_USER + $offset));
        expect(collect($other->fresh()->bot_dialog['buttons'] ?? [])->pluck('t')->all())->not->toContain('skip');
    }
});

it('creates nothing when the dialog is cancelled at the details step or at the summary', function (bool $afterDetails) {
    Event::fake([LeadCreated::class]);
    $user = cbStartDialog('quote');

    if ($afterDetails) {
        cbText('Need a printer');
    }

    cbPress('chat:cancel');

    expect(Lead::query()->count())->toBe(0)
        ->and($user->fresh()->bot_dialog['flow'] ?? null)->toBeNull()
        ->and(cbLast())->toBe(e(__('chatbot.menu_title', [], 'en')));
    Event::assertNotDispatched(LeadCreated::class);
})->with([false, true]);

it('lets the dialog expire after 30 minutes without answering the text as an unmatched phrase', function () {
    Event::fake([LeadCreated::class]);
    $user = cbStartDialog('quote');

    $this->travel(31)->minutes();
    cbText('Need a printer');

    expect(cbLast())->toBe(e(__('chatbot.dialog.expired', [], 'en')))
        ->and(ChatUnmatched::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and($user->fresh()->bot_dialog['flow'] ?? null)->toBeNull();
});

it('answers a stale Send after the expiry and creates nothing', function () {
    $user = cbStartDialog('quote');
    cbText('Need a printer');
    $send = cbButton($user, 'send');

    $this->travel(31)->minutes();
    cbPress($send);

    expect(Lead::query()->count())->toBe(0)
        ->and(cbLast())->toBe(e(__('chatbot.dialog.expired', [], 'en')));
});

it('keeps a dialog alive just inside the 30 minutes', function () {
    $user = cbStartDialog('quote');

    $this->travel(29)->minutes();
    cbText('Need a printer');

    expect(cbLast())->toContain(e(__('chatbot.dialog.summary.details', ['details' => 'Need a printer'], 'en')));
    cbPress(cbButton($user, 'send'));
    expect(Lead::query()->count())->toBe(1);
});

it('clears the dialog on the menu, /start, media and a hand-off', function (string $how) {
    $user = cbStartDialog('quote');
    expect($user->fresh()->bot_dialog['flow'])->not->toBeNull();

    match ($how) {
        'menu button' => cbPress('chat:menu'),
        'start' => cbText('/start'),
        'media' => app(HandleTelegramUpdate::class)->handle(telegramPrivateUpdate(cbNextId(), ['text' => null, 'photo' => [['file_id' => 'AgAD', 'file_unique_id' => 'u1', 'width' => 10, 'height' => 10]]], CB_USER)),
        'hand-off button' => cbPress('chat:handoff'),
        'reply menu key' => cbText(__('telegram_bot.menu.faq', [], 'en')),
    };

    expect($user->fresh()->bot_dialog['flow'] ?? null)->toBeNull()
        ->and(Lead::query()->count())->toBe(0);
})->with(['menu button', 'start', 'media', 'hand-off button', 'reply menu key']);

it('strips control and format characters and caps the answer at 500 characters', function () {
    $user = cbStartDialog('quote');
    $dirty = "Need\u{0007} scanners\u{200B} now\u{202E} ".str_repeat('x', 700);

    cbText($dirty);
    $summary = cbLast();
    cbPress(cbButton($user, 'send'));
    $message = Lead::query()->sole()->message;

    expect($message)->toStartWith('Need scanners now ')
        ->and(mb_strlen($message))->toBeLessThanOrEqual(500)
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', str_replace("\n", '', $message)))->toBe(0)
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', str_replace("\n", '', $summary)))->toBe(0);
});

it('keeps bot_dialog under 2 KB even for a very long multi-byte answer', function () {
    $user = cbStartDialog('quote');

    cbText(str_repeat("\u{1F600}", 4000));

    expect(strlen(json_encode($user->fresh()->bot_dialog, JSON_UNESCAPED_UNICODE)))->toBeLessThanOrEqual(2048)
        ->and($user->fresh()->bot_dialog['flow']['step'])->toBe('confirm');
});

it('treats text typed in a dialog as the answer: never the engine nor the unmatched list', function (string $typed) {
    $user = cbStartDialog('quote');

    cbText($typed);

    expect(ChatUnmatched::query()->count())->toBe(0)
        ->and(cbLast())->toContain(e(__('chatbot.dialog.confirm', [], 'en')))
        ->and($user->fresh()->bot_dialog['flow']['answers']['details'])->toBe($typed)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Bot);
})->with(['gibberish' => 'qwerty asdf zxcv', 'a rule keyword' => 'dostavka', 'a hand-off keyword' => 'menejer', 'a model' => 'ZD421']);

it('refuses a flow for a customer without a usable phone', function () {
    $user = cbUser(['phone' => '+88800001234567']);

    cbText('callback');

    expect(cbLast())->toBe(e(__('chatbot.dialog.refused', [], 'en')))
        ->and(Lead::query()->count())->toBe(0)
        ->and($user->fresh()->bot_dialog['flow'] ?? null)->toBeNull()
        ->and(RateLimiter::attempts('chat-lead:user:'.$user->id))->toBe(0);
});

it('checks a found product again: refused at the quote button when hidden', function () {
    $user = cbUser();
    $product = Product::factory()->create(['model' => 'QX9001', 'name' => ['uz' => 'Scanner QX9001', 'ru' => 'Scanner QX9001', 'en' => 'Scanner QX9001']]);
    cbText('QX9001');
    cbPress(cbButton($user, 'product'));
    $quote = cbButton($user, 'quote');
    $product->update(['is_active' => false]);

    cbPress($quote);

    expect(cbLast())->toBe(e(__('chatbot.search.unavailable', [], 'en')))
        ->and($user->fresh()->bot_dialog['flow'] ?? null)->toBeNull();
});

it('puts a visible product on the quote lead and drops one hidden before Send', function (bool $hide) {
    $user = cbUser();
    $product = Product::factory()->create(['model' => 'QX9002', 'name' => ['uz' => 'Scanner QX9002', 'ru' => 'Scanner QX9002', 'en' => 'Scanner QX9002']]);
    cbText('QX9002');
    cbPress(cbButton($user, 'product'));
    cbPress(cbButton($user, 'quote'));
    cbText('Need 10 pieces');
    $send = cbButton($user, 'send');

    if ($hide) {
        $product->update(['is_active' => false]);
    }

    cbPress($send);
    $lead = Lead::query()->with('items')->sole();

    expect($lead->type)->toBe(LeadType::Quote)
        ->and($lead->items->pluck('product_id')->all())->toBe($hide ? [] : [$product->id]);
})->with([false, true]);

it('re-prompts instead of creating an empty lead when the product is hidden and no details exist', function () {
    $user = cbUser();
    $product = Product::factory()->create(['model' => 'QX9003']);
    $user->forceFill(['bot_dialog' => ['gen' => 3, 'at' => now()->unix(), 'rules' => [], 'buttons' => [0 => ['t' => 'send', 'id' => 0]], 'flow' => [
        'flow' => 'quote', 'step' => 'confirm', 'started_at' => now()->unix(), 'answers' => ['details' => '', 'product_id' => $product->id],
    ]]])->save();
    $product->update(['is_active' => false]);

    cbPress('chat:b:3:0');

    expect(Lead::query()->count())->toBe(0)
        ->and(cbLast())->toContain(e(__('chatbot.dialog.required', [], 'en')))
        ->and($user->fresh()->bot_dialog['flow']['step'])->toBe('details')
        ->and(RateLimiter::attempts('chat-lead:user:'.$user->id))->toBe(0);
});

it('limits a customer to 3 leads a day and keeps the limiter for the end', function () {
    $user = cbUser();

    foreach (range(1, 3) as $ignored) {
        cbStartDialog('callback', $user);
        cbPress(cbButton($user, 'skip'));
        cbPress(cbButton($user, 'send'));
    }

    cbStartDialog('callback', $user);
    cbPress(cbButton($user, 'skip'));
    cbPress(cbButton($user, 'send'));

    expect(Lead::query()->count())->toBe(3)
        ->and(cbLast())->toBe(e(__('chatbot.dialog.limited', [], 'en')))
        ->and($user->fresh()->bot_dialog['flow'] ?? null)->toBeNull();
});

it('limits leads over all chats per hour', function () {
    config(['chatbot.leads.per_hour_global' => 2]);

    foreach ([0, 1, 2] as $n) {
        $user = cbUser(['phone' => '+99890123456'.$n], CB_USER + $n);
        cbStartDialog('callback', $user);
        cbPress(cbButton($user, 'skip'), $user->telegram_id);
        cbPress(cbButton($user, 'send'), $user->telegram_id);
    }

    expect(Lead::query()->count())->toBe(2)
        ->and(cbLast())->toBe(e(__('chatbot.dialog.limited', [], 'en')));
});

it('creates one lead when Telegram delivers the same update twice', function () {
    Event::fake([LeadCreated::class]);
    $user = cbStartDialog('callback');
    cbPress(cbButton($user, 'skip'));
    $send = cbButton($user, 'send');

    cbPress($send, CB_USER, 777001);
    cbPress($send, CB_USER, 777001);

    expect(Lead::query()->count())->toBe(1);
    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

it('creates one lead on a double tap of Send and answers it again', function () {
    Event::fake([LeadCreated::class]);
    $user = cbStartDialog('callback');
    cbPress(cbButton($user, 'skip'));
    $send = cbButton($user, 'send');

    cbPress($send);
    cbPress($send);

    expect(Lead::query()->count())->toBe(1)
        ->and(cbLast())->toContain('L-'.Lead::query()->sole()->id);
    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

it('ignores a button of an older answer', function () {
    $user = cbStartDialog('callback');
    $oldSkip = cbButton($user, 'skip');
    cbText('Call me after lunch');
    $before = cbSentCount();

    cbPress($oldSkip);

    expect(cbSentCount())->toBe($before)
        ->and($user->fresh()->bot_dialog['flow']['step'])->toBe('confirm')
        ->and($user->fresh()->bot_dialog['flow']['answers']['details'])->toBe('Call me after lunch');
});

it('drops a tampered or invalid stored dialog', function (array $flow) {
    $user = cbUser();
    $user->forceFill(['bot_dialog' => ['flow' => $flow]]);

    expect(LeadDialog::current($user, $dropped))->toBeNull()
        ->and($dropped)->toBeTrue()
        ->and($user->bot_dialog)->toBeNull();
})->with(function () {
    $ok = fn (array $override = []) => array_replace_recursive(['flow' => 'quote', 'step' => 'details', 'started_at' => now()->unix(), 'answers' => ['details' => '', 'product_id' => null]], $override);

    return [
        'unknown flow' => [$ok(['flow' => 'admin'])],
        'unknown step' => [$ok(['step' => 'paid'])],
        'flow is not a string' => [$ok(['flow' => ['quote']])],
        'started in the future' => [$ok(['started_at' => now()->unix() + 3600])],
        'started 31 minutes ago' => [$ok(['started_at' => now()->subMinutes(31)->unix()])],
        'started_at is a string' => [$ok(['started_at' => 'now'])],
        'details too long' => [$ok(['answers' => ['details' => str_repeat('a', 501)]])],
        'details with control characters' => [$ok(['answers' => ['details' => "a\u{0007}b"]])],
        'details not a string' => [$ok(['answers' => ['details' => ['x']]])],
        'product on a callback' => [$ok(['flow' => 'callback', 'answers' => ['product_id' => 5]])],
        'product id zero' => [$ok(['answers' => ['product_id' => 0]])],
        'product id a string' => [$ok(['answers' => ['product_id' => '5']])],
        'confirm quote without details or product' => [$ok(['step' => 'confirm'])],
        'answers missing' => [['flow' => 'quote', 'step' => 'details', 'started_at' => now()->unix()]],
    ];
});

it('keeps a valid stored dialog and reports no drop', function () {
    $user = cbUser();
    LeadDialog::start($user, LeadFlow::Quote, 12);

    $state = LeadDialog::current($user, $dropped);

    expect($dropped)->toBeFalse()
        ->and($state['flow'])->toBe(LeadFlow::Quote)
        ->and($state['step'])->toBe(ChatDialogStep::Details)
        ->and($state['answers']['product_id'])->toBe(12);
});

it('has no dialog and no drop for a user without a flow key', function () {
    $user = cbUser();

    expect(LeadDialog::current($user, $dropped))->toBeNull()->and($dropped)->toBeFalse();
});
