<?php

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\AiUsageChannel;
use App\Enums\AuditAction;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Chatbot\Settings as ChatbotSettingsScreen;
use App\Livewire\Admin\Chatbot\Stats;
use App\Models\ChatAiUsageDaily;
use App\Models\SettingsAudit;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/AiWizardHelpers.php';

/**
 * Plan 32 PW-24, §14 plan change 8, §9 "Settings": the AI product wizard
 * switch and the stage caps in Chat bot → Settings. Admin only (switch on and
 * off, caps), refused while the key is missing, audited, the key never shown,
 * a Content manager sees the state read-only.
 */
beforeEach(function () {
    config(['services.anthropic.key' => WIZ_KEY]);
    ChatSettings::forget();
});

function wizSettings(Role $role): Testable
{
    return Livewire::actingAs(staffUser($role))->test(ChatbotSettingsScreen::class);
}

function wizSave(array $data, Role $role): int
{
    $changed = app(SaveChatbotSettings::class)->handle($data, staffUser($role), '127.0.0.1');
    ChatSettings::forget();

    return $changed;
}

describe('defaults and visibility', function () {
    it('is off after a fresh install even with a key', function () {
        expect(AiSwitch::adminStored())->toBeFalse()->and(AiSwitch::adminEnabled())->toBeFalse();
    });

    it('is off without a key even when the switch was stored on', function () {
        wizOn(true, false);

        expect(AiSwitch::adminStored())->toBeTrue()->and(AiSwitch::adminEnabled())->toBeFalse();
    });

    it('shows the Admin the switch and the caps, and the Content manager a read-only state', function () {
        wizSettings(Role::Admin)->assertSeeHtml('data-ai-wizard-switch')->assertSeeHtml('data-ai-wizard-settings')->assertDontSeeHtml('data-ai-wizard-readonly');

        wizSettings(Role::ContentManager)->assertSeeHtml('data-ai-wizard-readonly')->assertDontSeeHtml('data-ai-wizard-switch');
    });

    it('refuses the settings screen to sales managers and service engineers', function (Role $role) {
        $this->actingAs(staffUser($role))->get(route('admin.chatbot.settings'))->assertForbidden();
    })->with(catalogViewOnlyRoles());

    it('never shows the key, in the page or in the component state', function () {
        $component = wizSettings(Role::Admin);

        expect($component->html())->not->toContain(WIZ_KEY)->not->toContain('sk-ant')
            ->and(json_encode($component->snapshot))->not->toContain(WIZ_KEY)->not->toContain('sk-ant');
    });

    it('lists the read-only stage models, efforts and limits from the config', function () {
        $html = wizSettings(Role::Admin)->html();

        expect($html)->toContain('claude-haiku-5-5')->toContain('claude-sonnet-5-5')->toContain('data-ai-wizard-usage');
    });
});

describe('switch (Admin only)', function () {
    it('lets an Admin switch it on and off through the screen, with audit rows', function () {
        $admin = staffUser(Role::Admin);
        $component = Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class);

        $component->set('form.ai_admin_enabled', true)->call('save')->assertHasNoErrors();
        ChatSettings::forget();

        expect(AiSwitch::adminEnabled())->toBeTrue();

        $component->set('form.ai_admin_enabled', false)->call('save')->assertHasNoErrors();
        ChatSettings::forget();

        $audits = SettingsAudit::query()->where('key', ChatSettings::key('ai_admin_enabled'))->get();

        expect(AiSwitch::adminEnabled())->toBeFalse()
            ->and($audits)->toHaveCount(2)
            ->and($audits->first()->area)->toBe(SettingsArea::Chatbot)
            ->and($audits->first()->user_id)->toBe($admin->id)
            ->and(json_encode($audits->toArray()))->not->toContain(WIZ_KEY);
    });

    it('refuses to switch it on while the key is missing and keeps it off', function () {
        config(['services.anthropic.key' => '']);

        wizSettings(Role::Admin)->set('form.ai_admin_enabled', true)->call('save')->assertHasErrors(['form.ai_admin_enabled']);
        ChatSettings::forget();

        expect(AiSwitch::adminStored())->toBeFalse()->and(SettingsAudit::query()->count())->toBe(0);
    });

    it('lets switching off through even without a key', function () {
        wizOn(true);
        config(['services.anthropic.key' => '']);

        wizSave(['ai_admin_enabled' => false], Role::Admin);

        expect(AiSwitch::adminStored())->toBeFalse();
    });

    it('refuses a Content manager both directions, in the action and through a tampered screen', function (bool $stored) {
        wizOn($stored);
        $manager = staffUser(Role::ContentManager);

        expect(fn () => app(SaveChatbotSettings::class)->handle(['ai_admin_enabled' => ! $stored], $manager, '127.0.0.1'))->toThrow(AuthorizationException::class);

        Livewire::actingAs($manager)->test(ChatbotSettingsScreen::class)->set('form.ai_admin_enabled', ! $stored)->call('save')->assertForbidden();
        ChatSettings::forget();

        expect(AiSwitch::adminStored())->toBe($stored)->and(SettingsAudit::query()->count())->toBe(0);
    })->with([true, false]);

    it('lets a Content manager save other settings and the unchanged switch value', function () {
        wizOn(true);

        wizSettings(Role::ContentManager)->set('form.products_shown', 5)->call('save')->assertHasNoErrors()->assertOk();
        ChatSettings::forget();

        expect(AiSwitch::adminStored())->toBeTrue();
    });

    it('writes no audit row when nothing changed', function () {
        wizOn(true);

        $changed = wizSave(['ai_admin_enabled' => true], Role::Admin);

        expect($changed)->toBe(0)->and(SettingsAudit::query()->where('action', AuditAction::Updated)->count())->toBe(0);
    });
});

describe('stage caps (Admin only)', function () {
    it('lets an Admin change the caps per stage without touching the chat caps', function () {
        $before = RecordAiUsage::caps(AiUsageChannel::Web);

        wizSave(['ai_adm_vision_daily_requests' => 12, 'ai_adm_vision_daily_tokens' => 345000, 'ai_adm_write_daily_requests' => 7, 'ai_adm_write_daily_tokens' => 99000], Role::Admin);

        expect(RecordAiUsage::caps(AiUsageChannel::AdminVision))->toBe(['requests' => 12, 'tokens' => 345000])
            ->and(RecordAiUsage::caps(AiUsageChannel::AdminWrite))->toBe(['requests' => 7, 'tokens' => 99000])
            ->and(RecordAiUsage::caps(AiUsageChannel::Web))->toBe($before)
            ->and(SettingsAudit::query()->count())->toBe(4);
    });

    it('lets an Admin save the caps through the screen and shows them back', function () {
        $component = wizSettings(Role::Admin)->set('form.ai_adm_write_daily_requests', 3)->call('save')->assertHasNoErrors();
        ChatSettings::forget();

        expect(RecordAiUsage::caps(AiUsageChannel::AdminWrite)['requests'])->toBe(3)
            ->and($component->get('form.ai_adm_write_daily_requests'))->toBe(3);
    });

    it('refuses a Content manager who changes a cap, in the action and through a tampered screen', function () {
        $manager = staffUser(Role::ContentManager);

        expect(fn () => app(SaveChatbotSettings::class)->handle(['ai_adm_write_daily_requests' => 5000], $manager, '127.0.0.1'))->toThrow(AuthorizationException::class);

        Livewire::actingAs($manager)->test(ChatbotSettingsScreen::class)->set('form.ai_adm_vision_daily_tokens', 1)->call('save')->assertForbidden();
        ChatSettings::forget();

        expect(RecordAiUsage::caps(AiUsageChannel::AdminWrite)['requests'])->toBe(100)
            ->and(RecordAiUsage::caps(AiUsageChannel::AdminVision)['tokens'])->toBe(2000000);
    });

    it('accepts the current value from a Content manager (not a change)', function () {
        expect(wizSave(['ai_adm_write_daily_requests' => 100, 'ai_adm_write_daily_tokens' => 1000000], Role::ContentManager))->toBe(0);
    });

    it('accepts zero (stage off) and rejects negative, non-integer and oversized caps', function () {
        wizSave(['ai_adm_vision_daily_requests' => 0], Role::Admin);

        expect(RecordAiUsage::caps(AiUsageChannel::AdminVision)['requests'])->toBe(0);

        foreach ([-1, 'abc', 1.5, config('chatbot.ai.caps.requests_max') + 1] as $value) {
            expect(fn () => wizSave(['ai_adm_write_daily_requests' => $value], Role::Admin))->toThrow(ValidationException::class);
        }
    });

    it('validates the caps on the screen', function (string $field, mixed $value) {
        wizSettings(Role::Admin)->set("form.{$field}", $value)->call('save')->assertHasErrors(["form.{$field}"]);
    })->with([
        ['ai_adm_vision_daily_requests', -5],
        ['ai_adm_vision_daily_tokens', -1],
        ['ai_adm_write_daily_requests', 999999999],
        ['ai_adm_write_daily_tokens', 999999999999],
    ]);
});

describe('usage (counts and USD per stage)', function () {
    it('shows the last 14 days per stage channel with the estimate at each stage\'s model price, and ignores the chat channels', function () {
        ChatAiUsageDaily::factory()->create(['channel' => AiUsageChannel::AdminVision, 'requests' => 4, 'input_tokens' => 1_000_000, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0]);
        ChatAiUsageDaily::factory()->create(['channel' => AiUsageChannel::AdminWrite, 'requests' => 2, 'input_tokens' => 1_000_000, 'output_tokens' => 1_000_000, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0]);
        ChatAiUsageDaily::factory()->create(['channel' => AiUsageChannel::Telegram, 'requests' => 999, 'input_tokens' => 9_000_000, 'output_tokens' => 9_000_000]);
        ChatAiUsageDaily::factory()->create(['channel' => AiUsageChannel::AdminWrite, 'day' => now()->subDays(40)->toDateString(), 'requests' => 888, 'input_tokens' => 5_000_000]);

        $html = wizSettings(Role::Admin)->html();
        $table = substr($html, (int) strpos($html, 'data-ai-wizard-usage'));
        $table = substr($table, 0, (int) strpos($table, '</table>'));

        // Haiku: 1M input = $1.00. Sonnet: 1M input + 1M output = $3 + $15 = $18.00.
        expect($table)->toContain('$1.00')->toContain('$18.00')->not->toContain('999')->not->toContain('888');
    });

    it('prices each stage at its configured model, and a bot channel at the chat price', function () {
        expect(Stats::usd(AiUsageChannel::AdminVision, 1_000_000, 0))->toBe(1.0)
            ->and(Stats::usd(AiUsageChannel::AdminWrite, 0, 1_000_000))->toBe(15.0)
            ->and(Stats::usd(AiUsageChannel::Web, 1_000_000, 0))->toBe(0.1);

        config(['chatbot.ai.product.write.model' => 'claude-haiku-5-5']);

        expect(Stats::usd(AiUsageChannel::AdminWrite, 0, 1_000_000))->toBe(5.0);
    });

    it('lists the stage rows in the chat bot statistics for an Admin', function () {
        ChatAiUsageDaily::factory()->create(['channel' => AiUsageChannel::AdminWrite, 'requests' => 6]);

        Livewire::actingAs(staffUser(Role::Admin))->test(Stats::class)->assertSee(AiUsageChannel::AdminWrite->label());
    });
});

describe('usage channels', function () {
    it('keeps ChatChannel to the two bot channels and every usage channel inside the column width', function () {
        expect(array_map(fn (ChatChannel $channel): string => $channel->value, ChatChannel::cases()))->toBe(['web', 'telegram']);

        foreach (AiUsageChannel::cases() as $channel) {
            expect(strlen($channel->value))->toBeLessThanOrEqual(10);

            foreach (['uz', 'ru', 'en'] as $locale) {
                expect(trans('enums.ai_usage_channel.'.$channel->value, [], $locale))->not->toBe('enums.ai_usage_channel.'.$channel->value);
            }
        }
    });
});
