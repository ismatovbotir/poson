<?php

use App\Actions\Catalog\UpdateCatalogImage;
use App\Actions\Mcp\CreateMcpPageDraft;
use App\Actions\Pages\SavePage;
use App\Actions\Telegram\CheckTelegramWebhook;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Pages\Form;
use App\Livewire\Admin\Pages\Index;
use App\Livewire\Admin\Telegram\Bot;
use App\Models\MonitoringState;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\InboxChat;
use App\Support\Telegram\Bot\TelegramStatus;
use Database\Seeders\PagesSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 21 E3 (enable fails closed on the inbox chat) and the privacy / offer
 * review flag (T21-24): abuse cases. Happy paths live in
 * tests/Feature/Telegram/TelegramBotPageTest.php and
 * tests/Feature/Admin/Content/PagesAdminTest.php. Bot API faked (fake token).
 *
 * Not repeated here, already covered:
 *  - case 1, second half (snapshot fail, getChat passes: enable succeeds):
 *    TelegramStatusAbuseTest "20: a failing snapshot does not block a bot switch the live checks allow".
 *  - case 10, slug half (a page titled "Privacy" gets a suffixed slug):
 *    PagesAdminTest "generates the slug from the title, never a reserved system or taken one"; here
 *    only the checklist consequence is added.
 *  - case 10, Index delete of a system page by a Content manager: PagesAdminTest
 *    "forbids the content manager to delete a system page" (contacts); here privacy / offer and the Form.
 *  - case 16, title edit and the whitespace / SEO-only title edit: PagesAdminTest "raises the review flag
 *    again when a content manager changes approved legal text" and "does not re-raise ..."; here the body.
 */
const TENA_CHAT = '-1001111111111';

/**
 * Per-test getChat answer (the first Http::fake() wins, so tests swap this holder).
 *
 * @return Closure|mixed|null
 */
function tenaChat(mixed $answer = null, bool $set = false): mixed
{
    static $holder = null;

    if ($set) {
        $holder = $answer;

        return null;
    }

    $value = $holder instanceof Closure ? $holder() : $holder;

    return $value ?? Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]);
}

function tenaSetChat(mixed $answer): void
{
    tenaChat($answer, set: true);
}

function tenaPublicChat(): mixed
{
    return Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup', 'username' => 'public_group']]);
}

function tenaPrivacy(bool $needsReview = false): Page
{
    return Page::factory()->system('privacy')->create(['needs_review' => $needsReview, 'is_active' => true]);
}

function tenaChatCalls(): int
{
    return count(array_filter(telegramApiCalls(), fn (array $call): bool => $call['method'] === 'getChat'));
}

function tenaEnable(): bool
{
    return app(SetBotEnabled::class)->enable(staffUser(), null);
}

function tenaRefusal(): ?TelegramBotException
{
    try {
        tenaEnable();
    } catch (TelegramBotException $exception) {
        return $exception;
    }

    return null;
}

/**
 * A cache whose reads of the chat-verdict keys throw.
 */
function tenaBreakChatCache(): void
{
    Cache::extend('tena-failing', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            if (str_starts_with((string) $key, 'telegram-inbox-chat')) {
                throw new RuntimeException('cache down MARKER-CACHE');
            }

            return parent::get($key);
        }
    }));
    config(['cache.stores.tena-failing' => ['driver' => 'tena-failing'], 'cache.default' => 'tena-failing']);
}

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function tenaPageData(Page $page, array $override = []): array
{
    return [
        'title' => $page->getTranslations('title'),
        'body' => $page->getTranslations('body'),
        'meta_title' => $page->getTranslations('meta_title'),
        'meta_description' => $page->getTranslations('meta_description'),
        'template' => $page->template->value,
        'sort' => $page->sort,
        'is_active' => $page->is_active,
        'needs_review' => $page->needs_review,
        ...$override,
    ];
}

function tenaLegalAudits(): int
{
    return SettingsAudit::query()->where('area', SettingsArea::Legal->value)->count();
}

function tenaManager(): User
{
    return staffUser(Role::ContentManager);
}

beforeEach(function () {
    tenaSetChat(null);
    telegramBotConfigured();

    Http::fake(['api.telegram.org/*' => fn ($request) => match (basename(parse_url($request->url(), PHP_URL_PATH))) {
        'getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://poson.example.uz/api/telegram/webhook', 'pending_update_count' => 0]]),
        'getChat' => tenaChat(),
        'getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'poson_test_bot']]),
        default => Http::response(['ok' => true, 'result' => true]),
    }]);
});

describe('enable fails closed on the chat', function () {
    it('1: refuses to enable when the stored snapshot says pass but getChat refuses', function () {
        tenaPrivacy();
        MonitoringState::query()->create(['key' => CheckTelegramWebhook::SNAPSHOT_KEY, 'ticked_at' => now(), 'meta' => [
            'checked_at' => now()->toIso8601String(), 'webhook_match' => true, 'last_error_at' => null, 'pending' => 0,
            'get_me' => 'ok', 'chat' => 'pass', 'chat_key' => 'sales', 'code' => null,
        ]]);
        tenaSetChat(tenaPublicChat());

        expect(tenaRefusal())->reason->toBe('not_ready')->failing->toBe(['chat'])
            ->and(BotSwitch::isEnabled())->toBeFalse();
    });

    it('2: throws when the cache fails during verify, and writes no switch row and no audit row', function () {
        tenaPrivacy();
        $admin = staffUser();
        tenaBreakChatCache();

        expect(fn () => app(SetBotEnabled::class)->enable($admin, null))->toThrow(RuntimeException::class);

        expect(BotSwitch::isEnabled())->toBeFalse()
            ->and(Setting::query()->where('key', BotSwitch::KEY)->exists())->toBeFalse()
            ->and(SettingsAudit::query()->count())->toBe(0);
    });

    it('3: refuses on a 429 or a 200 with ok:false, and caches neither', function (Closure $answer) {
        tenaPrivacy();
        $recovered = false;
        tenaSetChat(function () use (&$recovered, $answer) {
            return $recovered ? Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]) : $answer();
        });

        expect(tenaRefusal())->not->toBeNull()->and(BotSwitch::isEnabled())->toBeFalse();

        $recovered = true;

        expect(tenaEnable())->toBeTrue();
    })->with([
        '429' => [fn () => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 5]], 429)],
        '200 ok:false' => [fn () => Http::response(['ok' => false, 'description' => 'MARKER'], 200)],
    ]);

    it('3: refuses a 200 whose result is not an array or has no type', function (mixed $result) {
        tenaPrivacy();
        tenaSetChat(Http::response(['ok' => true, 'result' => $result]));

        expect(tenaRefusal())->reason->toBe('not_ready')->failing->toBe(['chat'])
            ->and(BotSwitch::isEnabled())->toBeFalse();
    })->with([
        'true' => [true],
        'a string' => ['supergroup'],
        'null' => [null],
        'no type' => [['id' => -1001111111111]],
        'a list' => [['group', 'supergroup']],
        'type is an array' => [['type' => ['supergroup']]],
    ]);

    it('4: a cached pass followed by a check-now that gets a 503 leaves enable refused with chat_unavailable', function () {
        tenaPrivacy();
        expect(InboxChat::verify(app(TelegramClient::class), TENA_CHAT))->toBeTrue();
        tenaSetChat(Http::response(['ok' => false], 503));

        Livewire::actingAs(staffUser())->test(Bot::class)->call('checkChat')->assertOk();

        expect(tenaRefusal())->reason->toBe('chat_unavailable')->and(BotSwitch::isEnabled())->toBeFalse();
    });

    it('7: a cached false refuses enable without a getChat call, and check-now then enable recovers', function () {
        tenaPrivacy();
        tenaSetChat(tenaPublicChat());
        expect(InboxChat::verify(app(TelegramClient::class), TENA_CHAT))->toBeFalse();

        // The chat is fixed since, but the cached false still decides.
        tenaSetChat(null);
        $calls = tenaChatCalls();

        expect(tenaRefusal())->reason->toBe('not_ready')->failing->toBe(['chat'])
            ->and(tenaChatCalls())->toBe($calls);

        Livewire::actingAs(staffUser())->test(Bot::class)->call('checkChat')->assertDispatched('toast', type: 'success');

        expect(tenaEnable())->toBeTrue();
    });

    it('8: another chat id, or a forget(), with a cached pass calls getChat again and a refusal blocks enable', function (Closure $change, ?string $expectedChat) {
        tenaPrivacy();
        expect(InboxChat::verify(app(TelegramClient::class), TENA_CHAT))->toBeTrue();
        $calls = tenaChatCalls();

        $change();
        tenaSetChat(tenaPublicChat());

        expect(tenaRefusal())->reason->toBe('not_ready')->failing->toBe(['chat'])
            ->and(tenaChatCalls())->toBe($calls + 1)
            ->and(collect(telegramApiCalls())->where('method', 'getChat')->last()['data']['chat_id'])->toBe($expectedChat ?? TENA_CHAT)
            ->and(BotSwitch::isEnabled())->toBeFalse();
    })->with([
        'a changed chat id' => [fn () => telegramBotConfigured(['services.telegram.chats.sales' => '-1004444444444']), '-1004444444444'],
        'a forget()' => [fn () => InboxChat::forget(), null],
    ]);

    it('9: disable works when getChat times out, and when the cache fails in the reload after the write', function (bool $breakCache) {
        tenaPrivacy();
        Setting::query()->create(['key' => BotSwitch::KEY, 'group' => 'telegram', 'value' => true]);
        confirmAdminPassword();
        tenaSetChat(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3000 milliseconds'));
        $component = Livewire::actingAs(staffUser())->test(Bot::class);

        if ($breakCache) {
            tenaBreakChatCache();
        }

        try {
            $component->call('disable');
        } catch (Throwable) {
            // A failing reload after the commit may surface; the write itself must have stuck.
        }

        $audit = SettingsAudit::query()->where('key', BotSwitch::KEY)->sole();

        expect(BotSwitch::isEnabled())->toBeFalse()->and($audit->action)->toBe(AuditAction::Disabled);
    })->with(['getChat timeout' => [false], 'cache failure in load()' => [true]]);
});

describe('checkChat', function () {
    it('5: when limited it makes no Telegram call and keeps the cached verdict', function () {
        $component = Livewire::actingAs(staffUser())->test(Bot::class);
        $component->call('checkChat')->assertDispatched('toast', type: 'success');
        $calls = count(telegramApiCalls());

        tenaSetChat(tenaPublicChat());
        $component->call('checkChat')->assertDispatched('toast', type: 'error');

        // The pass from the first check is still the cached verdict: it is read without a call.
        expect(count(telegramApiCalls()))->toBe($calls)
            ->and(InboxChat::verify(app(TelegramClient::class), TENA_CHAT))->toBeTrue()
            ->and(count(telegramApiCalls()))->toBe($calls);
    });

    it('5: when busy it makes no Telegram call, keeps the cached verdict and does not use up the allowance', function () {
        expect(InboxChat::verify(app(TelegramClient::class), TENA_CHAT))->toBeTrue();
        $calls = count(telegramApiCalls());
        $component = Livewire::actingAs(staffUser())->test(Bot::class);

        $lock = Cache::lock(TelegramStatus::LOCK_KEY, 30);
        $lock->get();
        tenaSetChat(tenaPublicChat());
        $component->call('checkChat')->assertDispatched('toast', type: 'error', body: __('admin.telegram_bot.chat_check.busy'));
        $lock->release();

        expect(count(telegramApiCalls()))->toBe($calls)
            ->and(InboxChat::verify(app(TelegramClient::class), TENA_CHAT))->toBeTrue();

        // Not limited: the busy answer cost nothing.
        $component->call('checkChat')->assertDispatched('toast', type: 'success');
        expect(count(telegramApiCalls()))->toBeGreaterThan($calls);
    });

    it('5: releases the lock when the check throws', function () {
        $this->mock(CheckTelegramWebhook::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new LogicException('MARKER-BOOM')));
        Exceptions::fake();

        $component = Livewire::actingAs(staffUser())->test(Bot::class);
        $component->call('checkChat')->assertDispatched('toast', type: 'error', body: __('admin.telegram_bot.chat_check.failed'));

        expect($component->html())->not->toContain('MARKER-BOOM')
            ->and(Cache::lock(TelegramStatus::LOCK_KEY, 5)->get())->toBeTrue();
    });

    it('6: a role revoked mid-session is refused on checkChat and nothing is called', function () {
        $admin = staffUser();
        $component = Livewire::actingAs($admin)->test(Bot::class);

        $admin->syncRoles([Role::SalesManager->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::actingAs($admin->fresh())->test(Bot::class)->assertForbidden();
        $component->call('checkChat')->assertForbidden();

        expect(telegramApiCalls())->toBe([]);
    });
});

describe('privacy and offer review flag', function () {
    it('10: a Content manager cannot delete privacy or offer through the index or the form', function (string $slug) {
        $page = Page::factory()->system($slug)->create();
        $manager = tenaManager();

        Livewire::actingAs($manager)->test(Index::class)->call('delete', $page->id)->assertForbidden();
        Livewire::actingAs($manager)->test(Form::class, ['page' => $page])->call('delete')->assertForbidden();

        expect($page->fresh())->not->toBeNull();
    })->with(['privacy', 'offer']);

    it('10: a new page titled "Privacy" takes a suffixed slug and never satisfies the checklist', function () {
        Livewire::actingAs(tenaManager())->test(Form::class)
            ->set('form.title.uz', 'Privacy')
            ->set('form.title.en', 'Privacy')
            ->set('form.is_active', true)
            ->set('form.needs_review', false)
            ->call('save')
            ->assertHasNoErrors();

        $page = Page::query()->sole();

        expect($page->slug)->not->toBe('privacy')->and($page->slug)->toStartWith('privacy')
            ->and($page->is_system)->toBeFalse()
            ->and(app(BotChecklist::class)->local()['privacy'])->toBeFalse();
    });

    it('11: an MCP draft titled Privacy or Offer gets a suffixed slug, is inactive and flagged, and leaves the real page alone', function (string $slug, string $title) {
        $existing = Page::factory()->system($slug)->create(['is_active' => true, 'needs_review' => false]);
        $before = $existing->fresh()->getAttributes();

        $draft = app(CreateMcpPageDraft::class)->handle(['title' => ['uz' => $title, 'en' => $title]]);

        expect($draft->slug)->not->toBe($slug)->and($draft->slug)->toStartWith($slug)
            ->and($draft->is_active)->toBeFalse()
            ->and($draft->needs_review)->toBeTrue()
            ->and($draft->is_system)->toBeFalse()
            ->and($existing->fresh()->getAttributes())->toBe($before);
    })->with([
        'privacy' => ['privacy', 'Privacy'],
        'offer' => ['offer', 'Offer'],
    ]);

    it('12: re-running PagesSeeder leaves a cleared flag alone, and a missing row returns with the flag on', function () {
        $data = ['privacy' => [
            'title' => ['uz' => 'Maxfiylik', 'ru' => 'Конфиденциальность', 'en' => 'Privacy'],
            'needs_review' => false,
        ]];

        app(PagesSeeder::class)->run($data);
        expect(Page::query()->where('slug', 'privacy')->sole()->needs_review)->toBeTrue();

        Page::query()->where('slug', 'privacy')->update(['needs_review' => false]);
        app(PagesSeeder::class)->run($data);
        expect(Page::query()->where('slug', 'privacy')->sole()->needs_review)->toBeFalse();

        Page::query()->where('slug', 'privacy')->delete();
        app(PagesSeeder::class)->run($data);
        expect(Page::query()->where('slug', 'privacy')->sole()->needs_review)->toBeTrue();
    });

    it('13: an admin clear with a rejected cover rolls back both the flag and the audit row', function () {
        $page = tenaPrivacy(needsReview: true);
        $this->mock(UpdateCatalogImage::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(ValidationException::withMessages(['image' => 'rejected'])));

        Livewire::actingAs(staffUser())->test(Form::class, ['page' => $page])
            ->set('form.needs_review', false)
            ->set('form.image', UploadedFile::fake()->image('cover.jpg'))
            ->call('save')
            ->assertHasErrors('form.image');

        expect($page->fresh()->needs_review)->toBeTrue()->and(SettingsAudit::query()->count())->toBe(0);
    });

    it('14: tampered review values from a Content manager keep the flag on privacy', function (mixed $value) {
        $page = tenaPrivacy(needsReview: true);
        $component = Livewire::actingAs(tenaManager())->test(Form::class, ['page' => $page]);

        try {
            $component->set('form.needs_review', $value)->call('save');
        } catch (Throwable) {
            // A type error on the property is as good as a refusal.
        }

        expect($page->fresh()->needs_review)->toBeTrue()->and(SettingsAudit::query()->count())->toBe(0)
            ->and(app(BotChecklist::class)->local()['privacy'])->toBeFalse();
    })->with([
        'the string "0"' => ['0'],
        'the integer 0' => [0],
        'an array' => [[0]],
        'an empty array' => [[]],
        'false' => [false],
    ]);

    it('14: a tampered slug on a custom page cannot turn it into the privacy page', function () {
        $page = Page::factory()->create(['slug' => 'careers', 'is_active' => true, 'needs_review' => false]);

        Livewire::actingAs(tenaManager())->test(Form::class, ['page' => $page])
            ->set('form.slug', 'privacy')
            ->set('form.title.uz', 'Vakansiyalar')
            ->call('save')
            ->assertHasNoErrors();

        expect($page->fresh()->slug)->toBe('careers')
            ->and(Page::query()->where('slug', 'privacy')->exists())->toBeFalse()
            ->and(app(BotChecklist::class)->local()['privacy'])->toBeFalse();
    });

    it('14: a direct SavePage call by a user with pages.update but not the Admin role cannot clear the flag', function () {
        $page = tenaPrivacy(needsReview: true);
        staffUser();
        $role = SpatieRole::query()->create(['name' => 'pages_editor', 'guard_name' => 'web'])->givePermissionTo('pages.update');
        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect($user->fresh()->can('pages.update'))->toBeTrue();

        app(SavePage::class)->handle($page, tenaPageData($page, ['needs_review' => false]), $user->fresh(), '203.0.113.7');

        expect($page->fresh()->needs_review)->toBeTrue()->and(tenaLegalAudits())->toBe(0);
    });

    it('15: stores the IP of an admin clear; writes no row when the flag is already off or stays on; a second clear after a re-raise writes a second row', function () {
        $page = tenaPrivacy(needsReview: true);
        $admin = staffUser();

        // Left on: no row.
        app(SavePage::class)->handle($page, tenaPageData($page), $admin, '203.0.113.7');
        expect(tenaLegalAudits())->toBe(0);

        // Cleared: one row with the IP.
        app(SavePage::class)->handle($page->fresh(), tenaPageData($page, ['needs_review' => false]), $admin, '203.0.113.7');
        $audit = SettingsAudit::query()->sole();
        expect($page->fresh()->needs_review)->toBeFalse()
            ->and($audit->ip)->toBe('203.0.113.7')
            ->and($audit->key)->toBe('pages.privacy.needs_review');

        // Already off, saved again: no new row.
        app(SavePage::class)->handle($page->fresh(), tenaPageData($page->fresh()), $admin, '203.0.113.7');
        expect(tenaLegalAudits())->toBe(1);

        // A Content manager edits the body: re-raised (own row), then the Admin clears again: second clear row.
        $manager = tenaManager();
        $fresh = $page->fresh();
        app(SavePage::class)->handle($fresh, tenaPageData($fresh, ['body' => ['uz' => '<p>Yangi matn</p>']]), $manager, '198.51.100.2');
        expect($page->fresh()->needs_review)->toBeTrue();

        app(SavePage::class)->handle($page->fresh(), tenaPageData($page->fresh(), ['needs_review' => false]), $admin, '203.0.113.7');

        expect(SettingsAudit::query()->where('key', 'pages.privacy.needs_review')->count())->toBe(2)
            ->and(SettingsAudit::query()->where('key', 'pages.privacy.review_reraised')->count())->toBe(1)
            ->and(json_encode(SettingsAudit::query()->get()->map->getAttributes()->all()))->not->toContain('Yangi');
    });

    it('16: a Content manager body edit of approved privacy or offer re-raises the flag and audits it without content', function (string $slug) {
        $page = Page::factory()->system($slug)->create(['body' => ['uz' => '<p>Matn</p>'], 'needs_review' => false]);
        $manager = tenaManager();

        Livewire::actingAs($manager)->test(Form::class, ['page' => $page])
            ->set('form.body.uz', '<p>MARKER-BODY yangi matn</p>')
            ->call('save')
            ->assertHasNoErrors();

        $audit = SettingsAudit::query()->sole();

        expect($page->fresh()->needs_review)->toBeTrue()
            ->and($audit->key)->toBe("pages.{$slug}.review_reraised")
            ->and($audit->user_id)->toBe($manager->id)
            ->and(json_encode($audit->getAttributes()))->not->toContain('MARKER');
    })->with(['privacy', 'offer']);

    it('16: a whitespace-only body edit or an SEO-only edit does not re-raise the flag', function (string $slug) {
        $page = Page::factory()->system($slug)->create(['body' => ['uz' => '<p>Matn</p>'], 'needs_review' => false]);

        Livewire::actingAs(tenaManager())->test(Form::class, ['page' => $page])
            ->set('form.body.uz', "  <p>Matn</p>\n")
            ->set('form.meta_description.uz', 'Yangi tavsif')
            ->call('save')
            ->assertHasNoErrors();

        expect($page->fresh()->needs_review)->toBeFalse()->and(tenaLegalAudits())->toBe(0);
    })->with(['privacy', 'offer']);
});
