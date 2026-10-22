<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\Company;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Site\AiBotRules;
use Livewire\Livewire;

it('shows the AI bots tab with each bot, its vendor and purpose, the presets and the advisory note', function () {
    $this->actingAs(staffUser())
        ->get(route('admin.settings.company', ['tab' => 'ai_bots']))
        ->assertOk()
        ->assertSee('data-settings-tab="ai_bots"', false)
        ->assertSee('data-bot="gptbot"', false)
        ->assertSee('data-bot="google-extended"', false)
        ->assertSee('data-search-bot="googlebot"', false)
        ->assertDontSee('data-bot="googlebot"', false)
        ->assertSee('data-bot-preset="search_only"', false)
        ->assertSee('data-ai-bots-note', false)
        ->assertSeeText('OpenAI')
        ->assertSeeText(__('analytics.enums.bot_purpose.training'));
});

it('forbids the tab and a direct save to every other role', function (Role $role) {
    $component = Livewire::actingAs(staffUser())->withQueryParams(['tab' => 'ai_bots'])->test(Company::class)
        ->call('applyBotPreset', AiBotRules::PRESET_SEARCH_ONLY);

    $this->actingAs(staffUser($role))
        ->get(route('admin.settings.company', ['tab' => 'ai_bots']))
        ->assertForbidden();

    $component->call('save')->assertForbidden();

    expect(Setting::query()->where('key', AiBotRules::SETTING_KEY)->exists())->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
]);

it('applies a preset, saves the full map and writes an audit without the value', function () {
    $admin = staffUser();

    Livewire::actingAs($admin)->withQueryParams(['tab' => 'ai_bots'])->test(Company::class)
        ->assertSet('aiBots.bots.gptbot', true)
        ->call('applyBotPreset', AiBotRules::PRESET_SEARCH_ONLY)
        ->assertSet('aiBots.bots.gptbot', false)
        ->assertSet('aiBots.bots.oai-searchbot', true)
        ->assertSee('aria-pressed="true"', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $setting = Setting::query()->where('key', AiBotRules::SETTING_KEY)->sole();
    $audit = SettingsAudit::query()->sole();

    expect($setting->group)->toBe('seo')
        ->and($setting->value)->toBe(AiBotRules::preset(AiBotRules::PRESET_SEARCH_ONLY))
        ->and($audit->area)->toBe(SettingsArea::AiBots)
        ->and($audit->key)->toBe(AiBotRules::SETTING_KEY)
        ->and($audit->action)->toBe(AuditAction::Updated)
        ->and($audit->user_id)->toBe($admin->id)
        ->and(json_encode($audit->getAttributes()))->not->toContain('deny');

    expect($this->get('/robots.txt')->getContent())->toContain("User-agent: GPTBot\nDisallow: /");
});

it('saves a single toggle, ignores unknown or search engine keys and audits only real changes', function () {
    $component = Livewire::actingAs(staffUser())->withQueryParams(['tab' => 'ai_bots'])->test(Company::class)
        ->set('aiBots.bots.perplexitybot', false)
        ->set('aiBots.bots.googlebot', false)
        ->call('save')
        ->assertHasNoErrors();

    $value = Setting::query()->where('key', AiBotRules::SETTING_KEY)->sole()->value;

    expect($value['perplexitybot'])->toBe('deny')
        ->and($value)->not->toHaveKey('googlebot')
        ->and(collect($value)->except('perplexitybot')->unique()->values()->all())->toBe(['allow']);

    $component->call('save');

    expect(SettingsAudit::query()->count())->toBe(1);
});

it('rejects a non-boolean toggle value', function () {
    Livewire::actingAs(staffUser())->withQueryParams(['tab' => 'ai_bots'])->test(Company::class)
        ->set('aiBots.bots.gptbot', 'yes-please')
        ->call('save')
        ->assertHasErrors('aiBots.bots.gptbot');

    expect(Setting::query()->where('key', AiBotRules::SETTING_KEY)->exists())->toBeFalse();
});

it('ignores an unknown preset', function () {
    Livewire::actingAs(staffUser())->withQueryParams(['tab' => 'ai_bots'])->test(Company::class)
        ->call('applyBotPreset', 'block_everything')
        ->assertSet('aiBots.bots.gptbot', true);
});
