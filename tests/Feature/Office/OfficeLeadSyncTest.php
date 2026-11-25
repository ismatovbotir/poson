<?php

use App\Actions\Office\CreateLeadFromBusinessChat;
use App\Actions\Office\SyncLeadFromBusinessChat;
use App\Enums\ActivityType;
use App\Enums\AgentRole;
use App\Enums\AnalysisStatus;
use App\Enums\BusinessAuthor;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Support\Office\PhoneSuggestion;
use App\Support\Telegram\LeadMessage;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 41 §6.8–6.9: leads from business chats (VO-7, VO-14).
*/

/**
 * @param  array<string, mixed>  $chat
 */
function officeLeadChat(array $chat = []): BusinessChat
{
    $connection = officeIngestReady(ai: true);

    return BusinessChat::factory()->client()->create(array_merge([
        'business_connection_id' => $connection->id,
        'telegram_chat_id' => OFFICE_CLIENT_ID,
        'first_name' => 'Aziza',
    ], $chat));
}

function officeDoneAnalysis(BusinessChat $chat, AgentRole $role = AgentRole::Sales): BusinessChatAnalysis
{
    return BusinessChatAnalysis::factory()->done('A long AI summary that must never reach the lead or the alert.')->create([
        'business_chat_id' => $chat->id,
        'role' => $role,
        'topic' => 'Label printer',
        'language' => 'uz',
        'lead_fields' => ['intent' => 'buy', 'products' => ['FAKE-SKU-1'], 'device_brand' => 'Zebra', 'device_model' => 'ZD421', 'urgency' => 'high'],
    ]);
}

it('creates a lead for an assigned chat with a shared phone: manager = assignee, text = topic + chat ref only (VO-14)', function () {
    $sales = staffUser(Role::SalesManager);
    $chat = officeLeadChat(['assignee_id' => $sales->id, 'phone' => '+998900000017']);

    $result = app(SyncLeadFromBusinessChat::class)->handle($chat, officeDoneAnalysis($chat));

    $lead = Lead::query()->sole();

    expect($result['action'])->toBe(BusinessChatAnalysis::LEAD_CREATED)
        ->and($lead->source)->toBe(LeadSource::TelegramBusiness)
        ->and($lead->type)->toBe(LeadType::Quote)
        ->and($lead->manager_id)->toBe($sales->id)
        ->and($lead->message)->toBe("Label printer\nTelegram Business chat #".$chat->id)
        ->and($lead->message)->not->toContain('summary')
        ->and($chat->fresh()->lead_id)->toBe($lead->id);
});

it('creates no lead for an unassigned chat: only a suggestion (VO-14)', function () {
    $chat = officeLeadChat(['phone' => '+998900000017']);

    $result = app(SyncLeadFromBusinessChat::class)->handle($chat, officeDoneAnalysis($chat));

    expect($result['action'])->toBe(BusinessChatAnalysis::LEAD_SUGGESTED)
        ->and(Lead::query()->count())->toBe(0);
});

it('creates no lead with auto-lead off or an assignee who cannot work with the type', function (string $case) {
    $assignee = $case === 'engineer_on_sales' ? staffUser(Role::ServiceEngineer) : staffUser(Role::SalesManager);
    $chat = officeLeadChat(['assignee_id' => $assignee->id, 'phone' => '+998900000017']);

    if ($case === 'auto_off') {
        officeSettings(['auto_lead' => false]);
    }

    expect(app(SyncLeadFromBusinessChat::class)->handle($chat, officeDoneAnalysis($chat))['action'])->toBe(BusinessChatAnalysis::LEAD_SUGGESTED)
        ->and(Lead::query()->count())->toBe(0);
})->with(['auto_off', 'engineer_on_sales']);

it('never uses a phone found in the text for a lead; it is only a suggestion for the form (VO-7)', function () {
    $sales = staffUser(Role::SalesManager);
    $chat = officeLeadChat(['assignee_id' => $sales->id]);
    $chat->messages()->create(['telegram_message_id' => 1, 'author' => BusinessAuthor::Client, 'kind' => 'text', 'text' => 'Raqamim 90 000 00 17']);
    $chat->messages()->create(['telegram_message_id' => 2, 'author' => BusinessAuthor::Owner, 'kind' => 'text', 'text' => 'Bizning raqam 90 000 00 88']);

    $result = app(SyncLeadFromBusinessChat::class)->handle($chat, officeDoneAnalysis($chat));

    expect($result['action'])->toBe(BusinessChatAnalysis::LEAD_NEEDS_PHONE)
        ->and(Lead::query()->count())->toBe(0)
        ->and(PhoneSuggestion::forChat($chat))->toBe('+998900000017');
});

it('uses the linked customer’s phone without matching another customer', function () {
    $sales = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create(['phone' => '+998900000066']);
    $chat = officeLeadChat(['assignee_id' => $sales->id, 'customer_id' => $customer->id]);

    app(SyncLeadFromBusinessChat::class)->handle($chat, officeDoneAnalysis($chat));

    expect(Lead::query()->sole()->customer_id)->toBe($customer->id);
});

it('updates an open lead with an AiSummary activity (meta only) and fills empty device fields', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $chat = officeLeadChat(['assignee_id' => $engineer->id, 'phone' => '+998900000017']);
    app(SyncLeadFromBusinessChat::class)->handle($chat, officeDoneAnalysis($chat, AgentRole::Service));
    $lead = Lead::query()->sole();
    $lead->forceFill(['device_brand' => null, 'device_model' => null])->save();

    $analysis = officeDoneAnalysis($chat->fresh(), AgentRole::Service);
    $result = app(SyncLeadFromBusinessChat::class)->handle($chat->fresh(), $analysis);

    $activity = $lead->activities()->where('type', ActivityType::AiSummary->value)->sole();

    expect($result['action'])->toBe(BusinessChatAnalysis::LEAD_UPDATED)
        ->and($activity->meta)->toBe(['analysis_id' => $analysis->id, 'topic' => 'Label printer'])
        ->and($activity->body)->toBeNull()
        ->and($lead->fresh()->device_brand)->toBe('Zebra')
        ->and(Lead::query()->count())->toBe(1);
});

it('sends a link-only group alert for a Telegram Business lead: no summary, name, phone or products (VO-14)', function () {
    $sales = staffUser(Role::SalesManager);
    config(['app.url' => 'https://poson.example.uz']);
    $chat = officeLeadChat(['assignee_id' => $sales->id, 'phone' => '+998900000017']);
    app(SyncLeadFromBusinessChat::class)->handle($chat, officeDoneAnalysis($chat));
    $lead = Lead::query()->sole()->load(['items', 'customer']);

    $message = LeadMessage::for($lead, 'ru');

    expect($message->html)->toContain($lead->reference())
        ->and($message->html)->not->toContain('Aziza')
        ->and($message->html)->not->toContain('90 000')
        ->and($message->html)->not->toContain('000017')
        ->and($message->html)->not->toContain('Label printer')
        ->and($message->html)->not->toContain('summary')
        ->and($message->buttons)->toHaveCount(1);
});

it('creates a lead from the form with a staff-confirmed phone', function () {
    $sales = staffUser(Role::SalesManager);
    $chat = officeLeadChat(['assignee_id' => $sales->id]);
    officeDoneAnalysis($chat);

    $lead = app(CreateLeadFromBusinessChat::class)->handle($sales, $chat, '90 000 00 17', LeadType::Quote);

    expect($lead->phone)->toBe('+998900000017')
        ->and($lead->created_by_id)->toBe($sales->id)
        ->and($lead->manager_id)->toBe($sales->id)
        ->and($chat->fresh()->phone)->toBe('+998900000017')
        ->and($chat->analyses()->where('status', AnalysisStatus::Done->value)->latest('id')->first()->lead_action)->toBe(BusinessChatAnalysis::LEAD_CREATED);
});

it('refuses the lead form on a chat the user cannot see (VO-9)', function () {
    $other = staffUser(Role::SalesManager);
    $chat = officeLeadChat(['assignee_id' => $other->id]);
    $sales = User::factory()->withRole(Role::SalesManager)->create();

    expect(fn () => app(CreateLeadFromBusinessChat::class)->handle($sales, $chat, '+998900000017', LeadType::Quote))->toThrow(AuthorizationException::class);
});
