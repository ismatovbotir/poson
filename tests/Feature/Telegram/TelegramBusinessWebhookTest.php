<?php

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Enums\Role;
use App\Livewire\Admin\Telegram\Bot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Plan 41 VO-15: the webhook gets the business update types without dropping
| pending updates, and only when it already points at this site.
*/

beforeEach(function () {
    telegramBotConfigured();
});

function businessWebhookInfo(string $url, array $allowed = ['message', 'callback_query', 'my_chat_member']): void
{
    Http::fake([
        'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => $url, 'pending_update_count' => 3, 'allowed_updates' => $allowed]]),
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'fake_bot']]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);
}

it('re-registers with the nine update types and drop_pending_updates false when the URL matches', function () {
    businessWebhookInfo(ConfigureTelegramWebhook::expectedUrl());

    expect(app(ConfigureTelegramWebhook::class)->updateAllowedUpdates(staffUser(), '10.0.0.1'))->toBe(ConfigureTelegramWebhook::SET);

    $call = collect(telegramApiCalls())->firstWhere('method', 'setWebhook');

    expect($call['data']['drop_pending_updates'])->toBeFalse()
        ->and($call['data']['allowed_updates'])->toHaveCount(9)
        ->and($call['data']['allowed_updates'])->toContain('business_message', 'business_connection', 'edited_business_message', 'deleted_business_messages');
});

it('skips a webhook that points elsewhere', function () {
    businessWebhookInfo('https://elsewhere.example/hook');

    expect(app(ConfigureTelegramWebhook::class)->updateAllowedUpdates(staffUser(), null))->toBe(ConfigureTelegramWebhook::SKIPPED)
        ->and(collect(telegramApiCalls())->pluck('method')->all())->not->toContain('setWebhook');
});

it('needs telegram.bot', function () {
    businessWebhookInfo(ConfigureTelegramWebhook::expectedUrl());

    expect(fn () => app(ConfigureTelegramWebhook::class)->updateAllowedUpdates(staffUser(Role::SalesManager), null))->toThrow(AuthorizationException::class);
});

it('compares allowed_updates and warns on the bot page when the business types are missing', function () {
    expect(ConfigureTelegramWebhook::hasAllowedUpdates(['allowed_updates' => ['message']]))->toBeFalse()
        ->and(ConfigureTelegramWebhook::hasAllowedUpdates(['allowed_updates' => ConfigureTelegramWebhook::ALLOWED_UPDATES]))->toBeTrue();

    businessWebhookInfo(ConfigureTelegramWebhook::expectedUrl());

    Livewire::actingAs(staffUser())->test(Bot::class)->call('refresh')
        ->assertSeeHtml('data-allowed-updates-missing');
});
