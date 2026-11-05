<?php

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\SettingsArea;
use App\Enums\TelegramUserState;
use App\Livewire\Admin\Telegram\Bot;
use App\Models\Page;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\BotText;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 21 B5 (plan 14 v2 §14 B6): the bot cannot be enabled without an active,
 * reviewed privacy page, with every other BotChecklist item passing, so privacy
 * alone is what refuses. The consent text links that same page.
 */
function privacyGateTelegram(): void
{
    Http::fake(['api.telegram.org/*' => function ($request) {
        return match (basename(parse_url($request->url(), PHP_URL_PATH))) {
            'getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => ConfigureTelegramWebhook::expectedUrl(), 'pending_update_count' => 0]]),
            'getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]),
            'getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'poson_test_bot', 'can_read_all_group_messages' => false]]),
            default => Http::response(['ok' => true, 'result' => true]),
        };
    }]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function privacyGatePage(array $attributes = []): Page
{
    return Page::factory()->system(BotChecklist::PRIVACY_SLUG)->create(['is_active' => true, 'needs_review' => false, ...$attributes]);
}

function privacyGateExpectRefused(): void
{
    expect(fn () => app(SetBotEnabled::class)->enable(staffUser(), '203.0.113.7'))
        ->toThrow(fn (TelegramBotException $refused) => expect($refused->reason)->toBe('not_ready')
            ->and($refused->failing)->toBe(['privacy']));

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->where('area', SettingsArea::TelegramBot)->count())->toBe(0);
}

beforeEach(function () {
    telegramBotConfigured();
    privacyGateTelegram();
});

it('enables with an active, reviewed privacy page and every other item passing', function () {
    privacyGatePage();

    expect(app(SetBotEnabled::class)->enable(staffUser(), '203.0.113.7'))->toBeTrue()
        ->and(BotSwitch::isEnabled())->toBeTrue();
});

it('refuses when the privacy page is inactive, with privacy the only failing item', function () {
    privacyGatePage(['is_active' => false]);

    privacyGateExpectRefused();
});

it('refuses when the privacy page needs review, with privacy the only failing item', function () {
    privacyGatePage(['needs_review' => true]);

    privacyGateExpectRefused();
});

it('refuses when the privacy page does not exist', function () {
    privacyGateExpectRefused();
});

it('refuses both cases as Admin on the bot screen, re-checked on the server', function (array $change) {
    $page = privacyGatePage();
    confirmAdminPassword();

    $screen = Livewire::actingAs(staffUser())->test(Bot::class)->assertSet('checklist.privacy', true);

    $page->forceFill($change)->save();

    $screen->call('enable')->assertDispatched('toast', type: 'error')->assertSet('checklist.privacy', false);

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->where('area', SettingsArea::TelegramBot)->count())->toBe(0);
})->with([
    'inactive' => [['is_active' => false]],
    'needs review' => [['needs_review' => true]],
]);

it('points the consent link at the page the gate checks, in every locale', function (string $locale) {
    privacyGatePage();
    $user = TelegramUser::factory()->create(['locale' => $locale, 'state' => TelegramUserState::Contact]);
    $url = route('site.page', ['locale' => $locale, 'page' => BotChecklist::PRIVACY_SLUG]);

    expect(BotText::render($user, ['consent.text']))->toContain($url);

    $this->get($url)->assertOk();
})->with(['uz', 'ru', 'en']);
