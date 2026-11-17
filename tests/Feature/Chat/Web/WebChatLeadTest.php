<?php

require_once __DIR__.'/WebChatHelpers.php';

use App\Actions\Chat\Web\ConvertWebChatToLead;
use App\Actions\Chat\Web\SubmitWebChatLead;
use App\Actions\Leads\SubmitSiteLead;
use App\Actions\Telegram\InboxRefused;
use App\Data\Leads\SiteLeadData;
use App\Enums\ChatAuthor;
use App\Enums\LeadFlow;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Events\LeadCreated;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\WebChatDialog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Plan 28 C5a, WC-8 / WC-9 / CB-17: the inline lead form (SubmitWebChatLead
 * through SubmitSiteLead::lead()) and the staff convert (ConvertWebChatToLead).
 * The widget-side GuardsLeadSubmissions order is C5b's (tested there).
 */
beforeEach(function () {
    wcSetup(defaults: false);
    Event::fake([LeadCreated::class]);
});

function wcFormChat(LeadFlow $flow, ?int $productId = null): WebChat
{
    return WebChat::factory()->create(['dialog' => WebChatDialog::withForm($flow, $productId)]);
}

it('keeps SubmitSiteLead::handle returning the reference through lead()', function () {
    $reference = app(SubmitSiteLead::class)->handle(new SiteLeadData('Ali', '+998901234567', null, null, 'uz'));

    expect($reference)->toBe(Lead::query()->sole()->reference());
});

it('web_lead_form_creates_one_lead_and_one_event', function () {
    $chat = wcFormChat(LeadFlow::Callback);

    $lead = app(SubmitWebChatLead::class)->handle($chat, 'Ali', '+998901234567', 'Call me after 5');
    $again = app(SubmitWebChatLead::class)->handle($chat, 'Ali', '+998901234567', 'again');

    $chat->refresh();

    expect($lead)->toBeInstanceOf(Lead::class)
        ->and($again)->toBeNull()
        ->and(Lead::query()->count())->toBe(1)
        ->and($lead->type)->toBe(LeadType::Callback)
        ->and($lead->source)->toBe(LeadSource::Site)
        ->and($chat->lead_id)->toBe($lead->id)
        ->and($chat->dialog)->toBeNull()
        ->and($chat->phone)->toBe('+998901234567');

    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

it('refuses without an open form, when banned or closed', function (array $attributes) {
    $chat = WebChat::factory()->create($attributes);

    expect(app(SubmitWebChatLead::class)->handle($chat, 'Ali', '+998901234567', null))->toBeNull()
        ->and(Lead::query()->count())->toBe(0);
})->with([
    'no form' => [['dialog' => null]],
    'tampered flow' => [['dialog' => ['form' => ['flow' => 'checkout', 'product_id' => null, 'at' => 1]]]],
    'banned' => [['dialog' => WebChatDialog::withForm(LeadFlow::Callback), 'banned_at' => now()]],
    'closed' => [['dialog' => WebChatDialog::withForm(LeadFlow::Callback), 'closed_at' => now()]],
]);

it('service_flow_with_a_product_is_a_service_request_without_items', function () {
    $product = wcProduct('QX4401');
    $chat = WebChat::factory()->create(['dialog' => ['form' => ['flow' => 'service', 'product_id' => $product->id, 'at' => now()->getTimestamp()]]]);

    $lead = app(SubmitWebChatLead::class)->handle($chat, 'Ali', '+998901234567', 'Printer is broken');

    expect($lead->type)->toBe(LeadType::ServiceRequest)
        ->and($lead->items()->count())->toBe(0);
});

it('quote_product_hidden_before_submit_dropped', function () {
    $visible = wcProduct('QX4402');
    $hidden = wcProduct('QX4403', ['is_active' => false]);

    $withProduct = app(SubmitWebChatLead::class)->handle(wcFormChat(LeadFlow::Quote, $visible->id), 'Ali', '+998901234567', '2 pcs');
    $withoutProduct = app(SubmitWebChatLead::class)->handle(wcFormChat(LeadFlow::Quote, $hidden->id), 'Ali', '+998901234568', '2 pcs');

    expect($withProduct->type)->toBe(LeadType::Quote)
        ->and($withProduct->items()->pluck('product_id')->all())->toBe([$visible->id])
        ->and($withoutProduct->type)->toBe(LeadType::Quote)
        ->and($withoutProduct->items()->count())->toBe(0);
});

it('WC9_a_visitor_phone_matching_a_customer_attaches_no_customer_or_manager', function () {
    $manager = User::factory()->create();
    Customer::factory()->create(['phone' => '+998901234567', 'manager_id' => $manager->id]);

    $formLead = app(SubmitWebChatLead::class)->handle($formChat = wcFormChat(LeadFlow::Callback), 'Ali', '+998901234567', null);
    $chat = WebChat::factory()->handedOff()->withContact('+998901234567')->create();
    $converted = app(ConvertWebChatToLead::class)->handle($chat, staffUser(Role::Admin), LeadType::Callback);

    expect($formLead->customer_id)->toBeNull()
        ->and($formLead->manager_id)->not->toBe($manager->id)
        ->and($formChat->fresh()->customer_id)->toBeNull()
        ->and($converted->customer_id)->toBeNull()
        ->and($converted->manager_id)->toBeNull()
        ->and($chat->fresh()->customer_id)->toBeNull();
});

it('convert_uses_server_phone_and_refuses_without_one', function () {
    $admin = staffUser(Role::Admin);
    $noPhone = WebChat::factory()->handedOff()->create();
    $chat = WebChat::factory()->handedOff()->withContact('+998901112233', 'Vali')->create();
    $message = $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'Need 3 scanners']);
    $bot = $chat->messages()->create(['author' => ChatAuthor::Bot, 'text' => 'bot text']);

    expect(fn () => app(ConvertWebChatToLead::class)->handle($noPhone, $admin, LeadType::Quote))->toThrow(InboxRefused::class)
        ->and(fn () => app(ConvertWebChatToLead::class)->handle($chat, $admin, LeadType::Quote, [$bot->id]))->toThrow(InboxRefused::class);

    $lead = app(ConvertWebChatToLead::class)->handle($chat, $admin, LeadType::Quote, [(string) $message->id]);

    expect($lead->phone)->toBe('+998901112233')
        ->and($lead->name)->toBe('Vali')
        ->and($lead->message)->toBe('Need 3 scanners')
        ->and($message->fresh()->lead_id)->toBe($lead->id)
        ->and($chat->fresh()->lead_id)->toBe($lead->id)
        ->and(app(ConvertWebChatToLead::class)->handle($chat, $admin, LeadType::Quote))->toBeNull()
        ->and(Lead::query()->count())->toBe(1);
});

it('convert_refuses_a_sales_manager_outside_the_inbox_scope', function () {
    $sales = staffUser(Role::SalesManager);
    $other = User::factory()->create();
    $botOnly = WebChat::factory()->withContact()->create();
    $othersChat = WebChat::factory()->handedOff()->assignedTo($other)->withContact()->create();
    $own = WebChat::factory()->handedOff()->withContact()->create();

    expect(fn () => app(ConvertWebChatToLead::class)->handle($botOnly, $sales, LeadType::Quote))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ConvertWebChatToLead::class)->handle($othersChat, $sales, LeadType::Quote))->toThrow(AuthorizationException::class)
        ->and(app(ConvertWebChatToLead::class)->handle($own, $sales, LeadType::Quote)->manager_id)->toBe($sales->id);
});

it('L1_per_user_limits_are_hit_before_the_transaction_and_a_refused_attempt_still_counts', function () {
    config(['cache.default' => 'database']);
    app()->forgetInstance(RateLimiter::class);
    Illuminate\Support\Facades\RateLimiter::clearResolvedInstances();
    $sales = staffUser(Role::SalesManager);
    $noPhone = WebChat::factory()->handedOff()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(fn () => app(ConvertWebChatToLead::class)->handle($noPhone, $sales, LeadType::Quote))->toThrow(InboxRefused::class);

    $firstCache = collect($queries)->search(fn (string $sql): bool => preg_match('/[`"]cache[`"]/', $sql) === 1);
    $lock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'web_chats') && str_contains($sql, 'select'));

    expect($firstCache)->not->toBeFalse()
        ->and($lock)->not->toBeFalse()
        ->and($firstCache)->toBeLessThan($lock)
        ->and(Illuminate\Support\Facades\RateLimiter::attempts(ConvertWebChatToLead::LIMIT_KEY.$sales->id))->toBe(1);
});
