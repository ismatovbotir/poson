<?php

use App\Enums\Role;
use App\Livewire\Admin\Customers\Show as CustomerShow;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 21 A7 (T21-18): the Customer "Telegram" block and the Lead "From Telegram"
 * link show no message text and no telegram_id; the thread link needs can('view').
 */
beforeEach(function () {
    telegramBotConfigured();
    Http::fake();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function crmBlockThread(Customer $customer, array $attributes = []): TelegramUser
{
    $chat = TelegramUser::factory()->registered()->create(['name' => 'Bot Person', 'username' => 'bot_person', 'telegram_id' => 987654321012, ...$attributes]);
    $chat->forceFill(['customer_id' => $customer->id])->save();

    return $chat;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function crmBlockLeadThread(Lead $lead, array $attributes = []): TelegramUser
{
    $chat = TelegramUser::factory()->registered()->create(['name' => 'Bot Person', 'telegram_id' => 987654321012, ...$attributes]);
    TelegramMessage::factory()->for($chat)->create(['text' => 'secret thread text'])->forceFill(['lead_id' => $lead->id])->save();

    return $chat;
}

it('shows an Admin the account, verified state and thread link, with no telegram_id or text', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $chat = crmBlockThread($customer);
    TelegramMessage::factory()->for($chat)->create(['text' => 'secret thread text']);

    $page = Livewire::actingAs($admin)->test(CustomerShow::class, ['customer' => $customer])
        ->assertSeeHtml('data-customer-telegram')
        ->assertSee('Bot Person')
        ->assertSee('@bot_person')
        ->assertSee(__('telegram_inbox.crm.unverified'))
        ->assertSeeHtml(route('admin.telegram.chats.show', $chat))
        ->assertDontSee('secret thread text');

    expect($page->html())->not->toContain('987654321012')
        ->and(json_encode($page->snapshot))->not->toContain('987654321012')->not->toContain('Bot Person');
});

it('shows the verified state', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    crmBlockThread($customer)->forceFill(['link_verified_at' => now()])->save();

    Livewire::actingAs($admin)->test(CustomerShow::class, ['customer' => $customer])
        ->assertSee(__('telegram_inbox.crm.verified'))
        ->assertDontSee(__('telegram_inbox.crm.unverified'));
});

it('hides the Customer block from a service engineer', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $customer = Customer::factory()->create();
    crmBlockThread($customer);

    Livewire::actingAs($engineer)->test(CustomerShow::class, ['customer' => $customer])
        ->assertOk()
        ->assertDontSeeHtml('data-customer-telegram')
        ->assertDontSee('Bot Person');
});

it('shows another manager no thread link and no identity on the Customer block', function () {
    $owner = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create();
    $chat = crmBlockThread($customer);
    $chat->forceFill(['manager_id' => $owner->id])->save();

    Livewire::actingAs($other)->test(CustomerShow::class, ['customer' => $customer])
        ->assertSeeHtml('data-customer-telegram')
        ->assertSeeHtml('data-telegram-hidden')
        ->assertDontSeeHtml('data-telegram-thread-link')
        ->assertDontSeeHtml(route('admin.telegram.chats.show', $chat))
        ->assertDontSee('Bot Person')
        ->assertDontSee('bot_person');

    Livewire::actingAs($owner)->test(CustomerShow::class, ['customer' => $customer])
        ->assertSeeHtml('data-telegram-thread-link')
        ->assertSee('Bot Person');
});

it('gives a thread link that opens only for those who may view the thread', function () {
    $owner = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create();
    $chat = crmBlockThread($customer);
    $chat->forceFill(['manager_id' => $owner->id])->save();

    $this->actingAs($other)->get(route('admin.telegram.chats.show', $chat))->assertForbidden();
});

it('has no block for a customer with no linked account or only an unregistered row', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();

    Livewire::actingAs($admin)->test(CustomerShow::class, ['customer' => $customer])->assertDontSeeHtml('data-customer-telegram');

    TelegramUser::factory()->unregistered()->create()->forceFill(['customer_id' => $customer->id])->save();

    Livewire::actingAs($admin)->test(CustomerShow::class, ['customer' => $customer])->assertDontSeeHtml('data-customer-telegram');
});

it('has no From Telegram block on a lead with no thread, and it survives a deleted thread', function () {
    $admin = staffUser(Role::Admin);
    $lead = Lead::factory()->create();

    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])->assertOk()->assertDontSeeHtml('data-lead-telegram');

    $chat = crmBlockLeadThread($lead);
    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])->assertSeeHtml('data-lead-telegram');

    $chat->delete();
    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertOk()->assertDontSeeHtml('data-lead-telegram');
});

it('links a lead to its thread for an Admin, with no text or telegram_id', function () {
    $admin = staffUser(Role::Admin);
    $lead = Lead::factory()->create();
    $chat = crmBlockLeadThread($lead);

    $page = Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])
        ->assertSeeHtml('data-telegram-thread-link')
        ->assertSeeHtml(route('admin.telegram.chats.show', $chat))
        ->assertSee('Bot Person')
        ->assertDontSee('secret thread text');

    expect($page->html())->not->toContain('987654321012');
});

it('shows no thread link on a lead to another manager and no block to a service engineer', function () {
    $owner = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = Lead::factory()->create(['manager_id' => null]);
    $chat = crmBlockLeadThread($lead);
    $chat->forceFill(['manager_id' => $owner->id])->save();

    Livewire::actingAs($other)->test(LeadShow::class, ['lead' => $lead])
        ->assertSeeHtml('data-lead-telegram')
        ->assertSeeHtml('data-telegram-hidden')
        ->assertDontSeeHtml('data-telegram-thread-link')
        ->assertDontSee('Bot Person');

    Livewire::actingAs($owner)->test(LeadShow::class, ['lead' => $lead])->assertSeeHtml('data-telegram-thread-link');

    $serviceLead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create();
    crmBlockLeadThread($serviceLead, ['telegram_id' => 987654321013]);

    Livewire::actingAs($engineer)->test(LeadShow::class, ['lead' => $serviceLead])
        ->assertOk()
        ->assertDontSeeHtml('data-lead-telegram')
        ->assertDontSee('Bot Person');
});
