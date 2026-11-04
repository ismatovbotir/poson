<?php

use App\Actions\Telegram\DeleteConversation;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\Integrations;
use App\Livewire\Admin\Telegram\Bot;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Livewire\Admin\Telegram\Status;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * Plan 21 A7 (T21-18, T21-20, T21-35): Admin delete of a conversation and the
 * inbox audit list.
 */
beforeEach(function () {
    telegramBotConfigured();
    Http::fake();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function chatDeleteThread(array $attributes = []): TelegramUser
{
    return TelegramUser::factory()->registered()->create($attributes);
}

function chatDeleteLead(TelegramUser $chat): Lead
{
    $lead = Lead::factory()->create(['message' => 'text copied into the lead']);
    TelegramMessage::factory()->for($chat)->create()->forceFill(['lead_id' => $lead->id])->save();

    return $lead;
}

function chatDeleteRealRequest(User $user, TelegramUser $chat, bool $deactivate): TestResponse
{
    $html = test()->actingAs($user)->withSession([PasswordConfirmation::SESSION_KEY => now()->unix()])->get(route('admin.telegram.chats.show', $chat))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $candidate) => array_key_exists('conversationId', json_decode($candidate, true)['data'] ?? []));
    expect($snapshot)->not->toBeNull();

    if ($deactivate) {
        $user->forceFill(['is_active' => false])->save();
    }

    return test()->withHeaders(['X-Livewire' => '1'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'deleteConversation', 'params' => [], 'metadata' => []]], 'updates' => (object) []]],
    ]);
}

it('deletes the thread with its messages and activities and audits by id only', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread(['name' => 'Private Person']);
    $other = chatDeleteThread();
    TelegramMessage::factory()->for($chat)->count(2)->create();
    $otherMessage = TelegramMessage::factory()->for($other)->create();
    Activity::factory()->subject($chat)->create();
    $otherActivity = Activity::factory()->subject($other)->create();

    app(DeleteConversation::class)->handle($chat, $admin, '10.0.0.1');

    expect(TelegramUser::query()->find($chat->id))->toBeNull()
        ->and(TelegramMessage::query()->pluck('id')->all())->toBe([$otherMessage->id])
        ->and(Activity::query()->pluck('id')->all())->toBe([$otherActivity->id]);

    $audit = SettingsAudit::query()->sole();
    expect($audit->area)->toBe(SettingsArea::TelegramInbox)
        ->and($audit->key)->toBe('telegram.conversation.'.$chat->id)
        ->and($audit->action)->toBe(AuditAction::Deleted)
        ->and($audit->user_id)->toBe($admin->id)
        ->and(json_encode($audit))->not->toContain('Private Person');
});

it('rolls the audit row and the delete back together when the delete fails', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread();
    TelegramMessage::factory()->for($chat)->create();
    Activity::factory()->subject($chat)->create();

    TelegramUser::deleted(function (): void {
        throw new RuntimeException('database down');
    });

    expect(fn () => app(DeleteConversation::class)->handle($chat, $admin, null))->toThrow(RuntimeException::class);
    TelegramUser::flushEventListeners();

    expect(TelegramUser::query()->find($chat->id))->not->toBeNull()
        ->and(TelegramMessage::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1)
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('refuses a manager by the action and by the screen, and hides the control', function () {
    $manager = staffUser(Role::SalesManager);
    $chat = TelegramUser::factory()->registered()->assigned($manager)->create();

    expect(fn () => app(DeleteConversation::class)->handle($chat, $manager, null))->toThrow(AuthorizationException::class);

    confirmAdminPassword();
    Livewire::actingAs($manager)->test(Show::class, ['telegramUser' => $chat])
        ->assertDontSeeHtml('data-delete-toggle')
        ->set('deleteOpen', true)
        ->assertDontSeeHtml('data-action-delete')
        ->assertDontSee(__('telegram_inbox.manage.delete_btn'))
        ->call('deleteConversation')
        ->assertForbidden();

    expect(TelegramUser::query()->find($chat->id))->not->toBeNull()
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('asks for the password first and deletes nothing without it', function (int $secondsAgo, bool $confirmed) {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread();

    if ($confirmed) {
        confirmAdminPassword($secondsAgo);
    }

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->set('deleteOpen', true)
        ->assertSeeHtml('data-password-required')
        ->call('deleteConversation')
        ->assertRedirect(route('admin.confirm-password'));

    expect(TelegramUser::query()->find($chat->id))->not->toBeNull()
        ->and(SettingsAudit::query()->count())->toBe(0)
        ->and(session('url.intended'))->toBe(route('admin.telegram.chats.show', $chat));
})->with([
    'never confirmed' => [0, false],
    'expired' => [901, true],
]);

it('deletes through the screen with a recent password and returns to the list', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread();
    confirmAdminPassword();

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->set('deleteOpen', true)
        ->assertDontSeeHtml('data-password-required')
        ->call('deleteConversation')
        ->assertRedirect(route('admin.telegram.chats'));

    expect(TelegramUser::query()->find($chat->id))->toBeNull()
        ->and(SettingsAudit::query()->sole()->ip)->not->toBeEmpty();
});

it('warns in the dialog when the thread is banned or linked to a lead', function (bool $banned, bool $lead) {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread($banned ? ['banned_at' => now()] : []);

    if ($lead) {
        chatDeleteLead($chat);
    }

    $page = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->assertDontSee(__('telegram_inbox.manage.delete_warn_banned'))
        ->assertDontSee(__('telegram_inbox.manage.delete_warn_lead'))
        ->set('deleteOpen', true);

    $confirm = html_entity_decode((string) str($page->html())->match('/wire:confirm="([^"]*)"[^>]*data-action-delete/'), ENT_QUOTES);

    expect($confirm)->toContain(__('telegram_inbox.manage.delete_confirm'))
        ->and(str_contains($confirm, __('telegram_inbox.manage.delete_warn_banned')))->toBe($banned)
        ->and(str_contains($confirm, __('telegram_inbox.manage.delete_warn_lead')))->toBe($lead);
    expect(substr_count($page->html(), 'data-delete-warning'))->toBe((int) $banned + (int) $lead);
})->with([
    'plain' => [false, false],
    'banned' => [true, false],
    'lead' => [false, true],
    'both' => [true, true],
]);

it('lets an Admin delete a lead-linked thread: the lead and its text survive', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread();
    $lead = chatDeleteLead($chat);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    expect(TelegramUser::query()->find($chat->id))->toBeNull()
        ->and(TelegramMessage::query()->count())->toBe(0)
        ->and($lead->fresh()?->message)->toBe('text copied into the lead');
});

it('loses the ban with a banned thread', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread(['banned_at' => now()]);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    expect(TelegramUser::query()->where('telegram_id', $chat->telegram_id)->exists())->toBeFalse();
});

it('returns 403 to a real delete request after the Admin is deactivated', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread();

    chatDeleteRealRequest($admin, $chat, deactivate: true)->assertForbidden();

    expect(TelegramUser::query()->find($chat->id))->not->toBeNull();
});

it('deletes on a real request from an active Admin with a recent password', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread();

    chatDeleteRealRequest($admin, $chat, deactivate: false)->assertOk();

    expect(TelegramUser::query()->find($chat->id))->toBeNull();
});

it('has the delete texts and audit label in every locale', function (string $locale) {
    foreach (['delete', 'delete_help', 'delete_confirm', 'delete_warn_banned', 'delete_warn_lead', 'delete_btn', 'delete_password'] as $key) {
        expect(Lang::has('telegram_inbox.manage.'.$key, $locale, false))->toBeTrue();
    }

    expect(Lang::has('telegram_inbox.toasts.deleted', $locale, false))->toBeTrue()
        ->and(Lang::has('enums.audit_action.deleted', $locale, false))->toBeTrue();
})->with(['uz', 'ru', 'en']);

/* ------------------------------------------------------------- inbox audit list */

it('lists inbox audit rows on the status page: key, action, who, when, no IP or content', function () {
    $admin = staffUser(Role::Admin);
    $chat = chatDeleteThread(['name' => 'Private Person']);
    TelegramMessage::factory()->for($chat)->create(['text' => 'secret text']);
    SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.'.$chat->id, AuditAction::Banned, $admin, '203.0.113.9');
    SettingsAudit::record(SettingsArea::TelegramBot, 'telegram.bot_enabled', AuditAction::Enabled, $admin, null);

    $page = Livewire::actingAs($admin)->test(Status::class)
        ->assertSeeHtml('data-inbox-audit-row')
        ->assertSee('telegram.conversation.'.$chat->id)
        ->assertSee(AuditAction::Banned->label())
        ->assertSee($admin->name)
        ->assertDontSee('203.0.113.9')
        ->assertDontSee('telegram.bot_enabled')
        ->assertDontSee('Private Person')
        ->assertDontSee('secret text');

    expect(substr_count($page->html(), 'data-inbox-audit-row'))->toBe(1);
});

it('paginates the inbox audit rows and shows an empty state', function () {
    $admin = staffUser(Role::Admin);

    Livewire::actingAs($admin)->test(Status::class)->assertSeeHtml('data-inbox-audit-empty');

    foreach (range(1, Status::AUDIT_PER_PAGE + 2) as $number) {
        SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.'.$number, AuditAction::Linked, $admin, null);
    }

    $first = Livewire::actingAs($admin)->test(Status::class);
    expect(substr_count($first->html(), 'data-inbox-audit-row'))->toBe(Status::AUDIT_PER_PAGE);

    $second = $first->call('gotoPage', 2, 'auditPage');
    expect(substr_count($second->html(), 'data-inbox-audit-row'))->toBe(2);
});

it('keeps the inbox audit list Admin only', function (Role $role) {
    $this->actingAs(staffUser($role))->get(route('admin.telegram.status'))->assertForbidden();
})->with([Role::SalesManager, Role::ContentManager, Role::ServiceEngineer]);

it('keeps inbox rows out of the Bot and Integrations lists', function () {
    $admin = staffUser(Role::Admin);
    SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.424242', AuditAction::Deleted, $admin, null);

    Livewire::actingAs($admin)->test(Bot::class)->assertDontSee('telegram.conversation.424242');
    Livewire::actingAs($admin)->test(Integrations::class)->assertDontSee('telegram.conversation.424242');
});
