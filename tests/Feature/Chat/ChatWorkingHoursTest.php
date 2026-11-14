<?php

require_once __DIR__.'/ChatBotHelpers.php';

use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\WorkingHours;
use App\Support\CompanySettings;
use Carbon\CarbonImmutable;

/**
 * Plan 28 §5, CB-31: WorkingHours in Asia/Tashkent (boundaries, the company
 * hours switch) and the after-hours text added to a hand-off reply.
 */
function cbHours(array $hours, bool $useCompany = false): void
{
    Setting::query()->updateOrCreate(['key' => 'chatbot.hours'], ['group' => SettingsArea::Chatbot->group(), 'value' => $hours]);
    Setting::query()->updateOrCreate(['key' => 'chatbot.use_company_hours'], ['group' => SettingsArea::Chatbot->group(), 'value' => $useCompany]);
    ChatSettings::forget();
}

function cbAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent');
}

it('is open from the opening minute and closed from the closing minute, in Tashkent time', function (string $at, bool $open) {
    // 2026-11-16 is a Monday; the default week is 09:00-18:00 Monday to Friday (company hours off).
    cbHours([]);

    expect(WorkingHours::isOpen(cbAt($at)))->toBe($open);
})->with([
    'before opening' => ['2026-11-16 08:59', false],
    'opening minute' => ['2026-11-16 09:00', true],
    'last open minute' => ['2026-11-16 17:59', true],
    'closing minute' => ['2026-11-16 18:00', false],
    'friday noon' => ['2026-11-20 12:00', true],
    'saturday noon' => ['2026-11-21 12:00', false],
    'sunday noon' => ['2026-11-22 12:00', false],
]);

it('reads the clock in Asia/Tashkent whatever the timezone of the given time', function () {
    cbHours([]);

    // 04:00 UTC is 09:00 in Tashkent (UTC+5); the previous minute is not.
    expect(WorkingHours::isOpen(CarbonImmutable::parse('2026-11-16 04:00', 'UTC')))->toBeTrue()
        ->and(WorkingHours::isOpen(CarbonImmutable::parse('2026-11-16 03:59', 'UTC')))->toBeFalse()
        // Sunday 20:00 UTC is already Monday 01:00 in Tashkent: still closed.
        ->and(WorkingHours::isOpen(CarbonImmutable::parse('2026-11-15 20:00', 'UTC')))->toBeFalse()
        // Monday 12:00 UTC is Monday 17:00 in Tashkent: open.
        ->and(WorkingHours::isOpen(CarbonImmutable::parse('2026-11-16 12:00', 'UTC')))->toBeTrue();
});

it('follows the configured weekly hours and closed days', function () {
    cbHours([
        'mo' => ['open' => false, 'from' => '09:00', 'to' => '18:00'],
        'sa' => ['open' => true, 'from' => '10:00', 'to' => '14:00'],
    ]);

    expect(WorkingHours::isOpen(cbAt('2026-11-16 12:00')))->toBeFalse()
        ->and(WorkingHours::isOpen(cbAt('2026-11-21 10:00')))->toBeTrue()
        ->and(WorkingHours::isOpen(cbAt('2026-11-21 14:00')))->toBeFalse();
});

it('uses the company opening hours while the switch is on and the company has any', function () {
    Setting::factory()->setting('opening_hours', [['days' => ['mo'], 'opens' => '08:00', 'closes' => '12:00']])->create();
    app()->forgetInstance(CompanySettings::class);
    cbHours([], useCompany: true);

    expect(WorkingHours::isOpen(cbAt('2026-11-16 08:00')))->toBeTrue()
        ->and(WorkingHours::isOpen(cbAt('2026-11-16 12:00')))->toBeFalse()
        ->and(WorkingHours::isOpen(cbAt('2026-11-17 10:00')))->toBeFalse();
});

it('adds the after-hours text to a hand-off outside working hours only', function (string $at, bool $open) {
    cbSetup();
    cbUser();
    cbHours([]);
    $this->travelTo(cbAt($at));

    cbPress('chat:handoff');

    $after = e(__('chatbot.after_hours', [], 'en'));

    expect(str_contains(cbLast(), $after))->toBe(! $open)
        ->and(cbLast())->toContain(e(__('chatbot.handoff', [], 'en')));
})->with([
    'monday at 10:00' => ['2026-11-16 10:00', true],
    'monday at 18:00' => ['2026-11-16 18:00', false],
    'sunday at noon' => ['2026-11-22 12:00', false],
]);

it('uses the admin after-hours text when one is set', function () {
    cbSetup();
    cbUser();
    cbHours([]);
    Setting::query()->updateOrCreate(['key' => 'chatbot.text.after_hours'], ['group' => SettingsArea::Chatbot->group(), 'value' => ['uz' => 'Hozir yopiqmiz', 'en' => 'We are closed now']]);
    ChatSettings::forget();
    $this->travelTo(cbAt('2026-11-22 12:00'));

    cbPress('chat:handoff');

    expect(cbLast())->toContain('We are closed now');
});
