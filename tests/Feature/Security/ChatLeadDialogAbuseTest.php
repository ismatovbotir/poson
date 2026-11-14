<?php

require_once __DIR__.'/ChatAbuseKit.php';

use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Events\LeadCreated;
use App\Models\ChatUnmatched;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C4 security abuse cases 17-24 (section 17): the Telegram lead
 * dialogs (callback, quote, service request) create at most one lead, run
 * only from server-side state and never leak into the engine, the lookups
 * or the unmatched list.
 */
beforeEach(fn () => labSetup());

/**
 * Walk a customer through the callback dialog to the summary; returns the summary message.
 *
 * @return array<string, mixed>|null
 */
function labCallbackToSummary(string $details = 'Please call me at five', int $userId = LAB_USER): ?array
{
    labTalk('call me back', $userId);

    return labTalk($details, $userId);
}

/**
 * Put a (possibly broken) lead dialog state on the customer, with a stored Send and Skip button.
 *
 * @param  array<string, mixed>  $override  "__offset" moves started_at (seconds), "__raw" replaces the whole flow
 */
function labTamperDialog(TelegramUser $user, array $override = []): void
{
    $flow = array_merge([
        'flow' => 'quote',
        'step' => 'confirm',
        'started_at' => now()->unix() + (int) ($override['__offset'] ?? 0),
        'answers' => ['details' => 'Need ten scanners', 'product_id' => null],
    ], array_diff_key($override, ['__offset' => 1, '__raw' => 1]));

    $user->forceFill(['bot_dialog' => [
        'rules' => [],
        'buttons' => [0 => ['t' => 'send', 'id' => 0], 1 => ['t' => 'skip', 'id' => 0]],
        'gen' => 3,
        'at' => now()->unix(),
        'flow' => $override['__raw'] ?? $flow,
    ]])->save();
}

function labFlowOf(TelegramUser $user): mixed
{
    return $user->fresh()->bot_dialog['flow'] ?? null;
}

// Abuse case 17
it('creates one lead for a double tap on Send and answers the second tap with the same thanks', function () {
    $user = labUser();
    $baseline = DB::transactionLevel();
    $levels = [];
    Event::listen(LeadCreated::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    labCallbackToSummary();
    $send = labStoredButton('send');

    $first = labPress($send);
    $second = labPress($send);
    $lead = Lead::query()->sole();

    expect(labText($first))->toBe(labLang('chatbot.dialog.sent', ['number' => 'L-'.$lead->id]))
        ->and(labText($second))->toBe(labText($first))
        ->and($levels)->toBe([$baseline])
        ->and(Cache::get('chat-lead-sent:'.$user->id))->toBe($lead->id);
});

// Abuse case 17
it('creates one lead when Telegram retries the same update', function () {
    labUser();
    labCallbackToSummary();
    $update = labCallbackUpdate(labStoredButton('send'));
    Event::fake([LeadCreated::class]);

    labDeliver($update);
    $retry = labDeliver($update);

    expect($retry)->toBeNull()
        ->and(Lead::query()->count())->toBe(1);

    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

// Abuse case 18
it('resets a tampered dialog on typed text: expired answer, no lead, nothing unmatched', function (array $tamper) {
    $user = labUser();
    labTamperDialog($user, $tamper);

    $reply = labTalk('Need ten scanners');

    expect(labText($reply))->toBe(labLang('chatbot.dialog.expired'))
        ->and(labFlowOf($user))->toBeNull()
        ->and(Lead::query()->count())->toBe(0)
        ->and(ChatUnmatched::query()->count())->toBe(0);
})->with([
    'unknown flow' => [['flow' => 'hack']],
    'unknown step' => [['step' => 'done']],
    'string product id' => [['answers' => ['details' => 'x', 'product_id' => '5']]],
    'details of 600 characters' => [['answers' => ['details' => str_repeat('a', 600), 'product_id' => null]]],
    'start in the future' => [['__offset' => 3600]],
    'start 31 minutes ago' => [['__offset' => -1860]],
    'callback flow with a product' => [['flow' => 'callback', 'answers' => ['details' => 'x', 'product_id' => 1]]],
    'flow that is a string' => [['__raw' => 'quote']],
]);

// Abuse case 18
it('creates no lead when Send is pressed on a tampered dialog', function (array $tamper) {
    $user = labUser();
    labTamperDialog($user, $tamper);

    $reply = labPress('chat:b:3:0');

    expect([null, labLang('chatbot.dialog.expired')])->toContain($reply === null ? null : labText($reply))
        ->and(Lead::query()->count())->toBe(0)
        ->and(labFlowOf($user))->toBeNull();
})->with([
    'unknown flow' => [['flow' => 'hack']],
    'unknown step' => [['step' => 'done']],
    'string product id' => [['answers' => ['details' => 'x', 'product_id' => '5']]],
    'details of 600 characters' => [['answers' => ['details' => str_repeat('a', 600), 'product_id' => null]]],
    'start in the future' => [['__offset' => 3600]],
    'start 31 minutes ago' => [['__offset' => -1860]],
    'callback flow with a product' => [['flow' => 'callback', 'answers' => ['details' => 'x', 'product_id' => 1]]],
]);

// Abuse case 18
it('sends the text after a reset dialog to the engine as usual', function () {
    $user = labUser();
    labTamperDialog($user, ['flow' => 'hack']);
    labTalk('Hello');

    $reply = labTalk('delivery');

    expect(labText($reply))->not->toBe(labLang('chatbot.dialog.expired'))->not->toBe('');
});

// Abuse case 19
it('ends the dialog on menu, /start, cancel, a photo and a hand-off, and a later Send makes no lead', function (string $action) {
    $user = labUser();
    labCallbackToSummary();
    $send = labStoredButton('send');
    expect(labFlowOf($user))->not->toBeNull();

    match ($action) {
        'menu button' => labPress('chat:menu'),
        'start command' => labTalk('/start'),
        'cancel button' => labPress('chat:cancel'),
        'menu text button' => labTalk(__('telegram_bot.menu.faq', [], 'en')),
        'hand-off button' => labPress('chat:handoff'),
        'photo' => labDeliver(telegramPrivateUpdate(labNextId(), ['text' => null, 'photo' => [['file_id' => 'f', 'file_unique_id' => 'u', 'width' => 9, 'height' => 9, 'file_size' => 10]]], LAB_USER)),
    };

    expect(labFlowOf($user))->toBeNull();

    $later = labPress($send);

    expect([null, labLang('chatbot.dialog.expired')])->toContain($later === null ? null : labText($later))
        ->and(Lead::query()->count())->toBe(0);
})->with(['menu button', 'start command', 'cancel button', 'menu text button', 'hand-off button', 'photo']);

// Abuse case 20
it('takes text typed during a dialog as the answer and never as a lookup, a manager request or an unmatched phrase', function (string $text) {
    $user = labUser();
    labOrder();
    labRepair();
    labTalk('call me back');

    [$reply, $counts] = labQueries(fn () => labTalk($text), 'orders', 'repairs', 'products');
    $user = $user->fresh();

    expect(labText($reply))->toContain(labLang('chatbot.dialog.confirm'))->toContain(e($text))
        ->and($counts)->toBe(['orders' => 0, 'repairs' => 0, 'products' => 0])
        ->and($user->bot_dialog['flow']['answers']['details'])->toBe($text)
        ->and($user->chat_mode->value)->toBe('bot')
        ->and(ChatUnmatched::query()->count())->toBe(0)
        ->and(TelegramMessage::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('chat-lookup:user:'.$user->id))->toBe(0);
})->with(['manager in Russian' => 'менеджер', 'manager in English' => 'manager', 'order number' => 'O-2026-0001', 'repair number' => 'R-2026-000123', 'model' => 'ZXQ100-A']);

// Abuse case 21
it('strips control, format and bidi characters from a dialog answer', function () {
    $user = labUser();
    labTalk('call me back');

    labTalk("Hello\u{202E}evil\u{200B}\u{0007}\u{2066}world");

    $details = $user->fresh()->bot_dialog['flow']['answers']['details'];

    expect($details)->toContain('Hello')->toContain('world')
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', $details))->toBe(0);
});

// Abuse case 21
it('caps a dialog answer and the lead message at 500 characters', function () {
    $user = labUser();
    labCallbackToSummary(str_repeat('x', 700));

    expect(mb_strlen($user->fresh()->bot_dialog['flow']['answers']['details']))->toBe(500);

    labPress(labStoredButton('send'));

    expect(mb_strlen((string) Lead::query()->sole()->message))->toBeLessThanOrEqual(500);
});

// Abuse case 21
it('keeps bot_dialog under 2 KB (as UTF-8) for a 500-emoji answer', function () {
    $user = labUser();
    labCallbackToSummary(str_repeat('😀', 500));

    $stored = $user->fresh()->bot_dialog;

    expect(strlen(json_encode($stored, JSON_UNESCAPED_UNICODE)))->toBeLessThanOrEqual(2048)
        ->and($stored['flow']['answers']['details'])->not->toBe('');

    labPress(labStoredButton('send'));

    expect(Lead::query()->count())->toBe(1);
});

// Abuse case 21
it('escapes markup in the summary and in the sales alert of the lead', function () {
    labUser();
    $summary = labCallbackToSummary('<b>bold</b> & <script>alert(1)</script>');

    expect(labText($summary))->toContain('&lt;script&gt;')->not->toContain('<script>');

    labPress(labStoredButton('send'));

    $alert = collect(labSentCalls())->first(fn (array $call): bool => (string) $call['data']['chat_id'] === '-1001111111111');

    expect($alert)->not->toBeNull()
        ->and($alert['data']['text'])->toContain('&lt;script&gt;')->not->toContain('<script>');
});

// Abuse case 22
it('creates the lead without the product when it was hidden after Request a quote', function () {
    labUser();
    $product = labProduct('ZXQ100-A');
    labTalk('ZXQ100');
    labPress(labStoredButton('product'));
    labPress(labStoredButton('quote'));
    labTalk('Need five pieces');
    $send = labStoredButton('send');

    $product->update(['is_active' => false]);
    labPress($send);

    $lead = Lead::query()->sole();

    expect($lead->type)->toBe(LeadType::Quote)
        ->and($lead->items)->toHaveCount(0)
        ->and(DB::table('lead_items')->count())->toBe(0)
        ->and($lead->message)->toBe('Need five pieces');
});

// Abuse case 22
it('asks again instead of creating an empty lead when the quote product is hidden and nothing else was written', function () {
    $user = labUser();
    $product = labProduct('ZXQ110-A');
    labTamperDialog($user, ['flow' => 'quote', 'answers' => ['details' => '', 'product_id' => $product->id]]);
    $product->update(['is_active' => false]);

    $reply = labPress('chat:b:3:0');

    expect(labText($reply))->toContain(labLang('chatbot.dialog.required'))
        ->and(Lead::query()->count())->toBe(0)
        ->and(DB::table('lead_items')->count())->toBe(0)
        ->and(RateLimiter::attempts('chat-lead:user:'.$user->id))->toBe(0)
        ->and(labFlowOf($user)['step'])->toBe('details');
});

// Abuse case 23
it('refuses an anonymous +888 customer a lead dialog and creates no row', function () {
    $user = labUser(['phone' => '+88800012345']);

    $reply = labTalk('call me back');

    expect(labText($reply))->toBe(labLang('chatbot.dialog.refused'))
        ->and(labFlowOf($user))->toBeNull()
        ->and(Lead::query()->count())->toBe(0);
});

// Abuse case 23
it('creates no lead when a +888 customer presses Send on a dialog that was put in place', function () {
    $user = labUser(['phone' => '+88800012345']);
    labTamperDialog($user);

    $reply = labPress('chat:b:3:0');

    expect(labText($reply))->toBe(labLang('chatbot.dialog.refused'))
        ->and(Lead::query()->count())->toBe(0);
});

// Abuse case 23
it('does not attach an unlinked CRM customer with the same phone nor auto-assign their manager', function () {
    labUser();
    $manager = staffUser(Role::SalesManager);
    Customer::factory()->managedBy($manager)->create(['phone' => LAB_PHONE]);

    labCallbackToSummary();
    labPress(labStoredButton('send'));

    $lead = Lead::query()->sole();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->manager_id)->toBeNull()
        ->and($lead->source)->toBe(LeadSource::Telegram)
        ->and($lead->type)->toBe(LeadType::Callback)
        ->and($lead->phone)->toBe(LAB_PHONE);
});

// Abuse case 24
it('limits a customer to three leads a day and creates no row for the fourth', function () {
    $user = labUser();

    foreach (range(1, 3) as $n) {
        labCallbackToSummary();
        expect(labText(labPress(labStoredButton('send'))))->toContain('L-');
    }

    labCallbackToSummary();
    $reply = labPress(labStoredButton('send'));

    expect(labText($reply))->toBe(labLang('chatbot.dialog.limited'))
        ->and(Lead::query()->count())->toBe(3)
        ->and(labFlowOf($user))->toBeNull();
});

// Abuse case 24
it('limits all customers together to 30 leads an hour and creates no row for the 31st', function () {
    labUser();

    foreach (range(1, 30) as $hit) {
        RateLimiter::hit('chat-lead:global', 3600);
    }

    labCallbackToSummary();
    $reply = labPress(labStoredButton('send'));

    expect(labText($reply))->toBe(labLang('chatbot.dialog.limited'))
        ->and(Lead::query()->count())->toBe(0);
});

// Abuse case 24
it('does not use up the lead limits on phone refusals', function () {
    $user = labUser(['phone' => '+88800012345']);

    foreach (range(1, 5) as $attempt) {
        labTalk('call me back');
        labTamperDialog($user);
        labPress('chat:b:3:0');
    }

    expect(RateLimiter::attempts('chat-lead:user:'.$user->id))->toBe(0)
        ->and(RateLimiter::attempts('chat-lead:global'))->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});
