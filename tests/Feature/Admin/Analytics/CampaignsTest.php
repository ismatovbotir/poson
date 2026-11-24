<?php

use App\Actions\Analytics\ExportOfflineConversions;
use App\Enums\AnalyticsGoal;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Analytics\Campaigns;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Analytics\CampaignReport;
use App\Support\CompanySettings;
use App\Support\Money;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Plan 40 T4 / T6 (YD-8 switch, YD-13, YD-14, YD-16).
 */
beforeEach(fn () => config(['analytics.report_cache_seconds' => 0]));

const YD_DIRECT_UTM = ['utm_source' => 'Yandex', 'utm_medium' => 'cpc', 'utm_campaign' => '12345'];

function ydCampaignFixture(): void
{
    $lead = Lead::factory()->fromDirect()->create(['utm' => YD_DIRECT_UTM]);
    Lead::factory()->create(['utm' => YD_DIRECT_UTM]);
    Lead::factory()->create(['utm' => ['utm_source' => 'newsletter']]);
    Lead::factory()->create(['utm' => null]);

    // Counted: one placed and one paid Direct order.
    Order::factory()->totalling(1_000_000_00)->create(['attribution' => ['channel' => 'campaign', ...YD_DIRECT_UTM]]);
    Order::factory()->totalling(2_000_000_00)->paid()->create(['attribution' => ['channel' => 'campaign', ...YD_DIRECT_UTM]]);
    // Not counted: a TEST order and a cancelled one; a refunded one is placed but not paid.
    Order::factory()->test()->totalling(9_000_000_00)->paid()->create(['attribution' => YD_DIRECT_UTM]);
    Order::factory()->status(OrderStatus::Cancelled)->totalling(8_000_000_00)->create(['attribution' => YD_DIRECT_UTM]);
    Order::factory()->totalling(500_000_00)->create(['attribution' => YD_DIRECT_UTM, 'payment_status' => PaymentStatus::Refunded]);

    Quote::factory()->status(QuoteStatus::Accepted)->totalling(3_000_000_00)->create(['lead_id' => $lead->id]);
    Quote::factory()->status(QuoteStatus::Rejected)->totalling(7_000_000_00)->create(['lead_id' => $lead->id, 'accepted_at' => now()]);
}

it('is Admin only', function (Role $role, int $status) {
    $this->actingAs(staffUser($role))->get(route('admin.analytics.campaigns'))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'content manager' => [Role::ContentManager, 403],
    'sales manager' => [Role::SalesManager, 403],
    'service engineer' => [Role::ServiceEngineer, 403],
]);

it('redirects guests to the login page', function () {
    $this->get(route('admin.analytics.campaigns'))->assertRedirect(route('admin.login'));
});

it('groups by lowercased UTM tags and excludes test and cancelled orders and non-accepted quotes', function () {
    ydCampaignFixture();

    $report = (new CampaignReport(30))->build();
    $direct = collect($report['rows'])->firstWhere('source', 'yandex');

    expect($direct)->toMatchArray([
        'medium' => 'cpc', 'campaign' => '12345',
        'leads' => 2, 'leads_with_click' => 1,
        'orders' => 3, 'orders_value' => 3_500_000_00, 'paid_value' => 2_000_000_00,
        'quotes' => 1, 'quotes_value' => 3_000_000_00,
    ])
        ->and(collect($report['rows'])->firstWhere('source', CampaignReport::NONE)['leads'])->toBe(1)
        ->and($report['totals']['leads'])->toBe(4)
        ->and($report['totals']['orders'])->toBe(3);

    $directOnly = (new CampaignReport(30, directOnly: true))->build();

    expect(collect($directOnly['rows'])->pluck('source')->all())->toBe(['yandex'])
        ->and($directOnly['totals']['leads'])->toBe(2);
});

it('shows the numbers with Money and escapes UTM values', function () {
    ydCampaignFixture();
    Lead::factory()->create(['utm' => ['utm_source' => 'x', 'utm_campaign' => '<script>alert(1)</script>'.str_repeat('a', 200)]]);

    $html = $this->actingAs(staffUser())->get(route('admin.analytics.campaigns'))->assertOk()->getContent();

    expect($html)->toContain(e(Money::format(3_500_000_00)))
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain(str_repeat('a', 100));
});

it('keeps the top groups and folds the rest into one other row', function () {
    foreach (range(1, CampaignReport::MAX_GROUPS + 3) as $index) {
        Lead::factory()->create(['utm' => ['utm_source' => 'yandex', 'utm_campaign' => 'c'.$index]]);
    }

    $rows = (new CampaignReport(30))->build()['rows'];

    expect($rows)->toHaveCount(CampaignReport::MAX_GROUPS + 1)
        ->and(end($rows)['source'])->toBe(CampaignReport::OTHER)
        ->and(end($rows)['leads'])->toBe(3);
});

describe('YD-8 the ad click id switch', function () {
    it('is off by default, needs a recent password and is audited', function () {
        $admin = staffUser();
        $this->actingAs($admin);

        expect(app(CompanySettings::class)->adClickIdsEnabled())->toBeFalse();

        Livewire::test(Campaigns::class)->set('clickIdsEnabled', true)->call('saveClickIds')->assertRedirect(route('admin.confirm-password'));
        expect(Setting::query()->where('key', CompanySettings::AD_CLICK_IDS_KEY)->exists())->toBeFalse();

        confirmAdminPassword();
        Livewire::test(Campaigns::class)->set('clickIdsEnabled', true)->call('saveClickIds')->assertNoRedirect();

        CompanySettings::flush();
        $audit = SettingsAudit::query()->sole();

        expect(app(CompanySettings::class)->adClickIdsEnabled())->toBeTrue()
            ->and($audit->area)->toBe(SettingsArea::Analytics)
            ->and($audit->action)->toBe(AuditAction::Enabled)
            ->and($audit->user_id)->toBe($admin->id);
    });

    it('cannot be changed by other staff', function () {
        $this->actingAs(staffUser(Role::ContentManager));

        Livewire::test(Campaigns::class)->assertForbidden();
    });

    it('shows the CSV form only while on, with a POST and CSRF field', function () {
        $this->actingAs(staffUser());

        $this->get(route('admin.analytics.campaigns'))->assertSee('data-export-disabled', false)->assertDontSee('data-export-form', false);

        Setting::query()->create(['key' => CompanySettings::AD_CLICK_IDS_KEY, 'group' => 'seo', 'value' => true]);
        CompanySettings::flush();

        $html = $this->get(route('admin.analytics.campaigns'))->assertOk()->getContent();

        expect($html)->toContain('method="POST" action="'.route('admin.analytics.offline-conversions').'"')
            ->toContain('name="_token"')
            ->toContain('value="quote_accepted"');
    });
});

it('YD-14: lead and order pages show only "Yandex Direct click: yes / no"', function () {
    $this->actingAs(staffUser());
    $lead = Lead::factory()->fromDirect()->create();
    $order = Order::factory()->fromDirect()->create();

    $leadHtml = $this->get(route('admin.leads.show', $lead))->assertOk()->assertSee(__('crm.leads.fields.yandex_direct_click'))->getContent();
    $orderHtml = $this->get(route('admin.orders.show', $order))->assertOk()->assertSee(__('orders.fields.yandex_direct_click'))->getContent();

    foreach ([$leadHtml, $orderHtml] as $html) {
        expect($html)->not->toContain($lead->yclid)->not->toContain($lead->ym_client_id);
    }

    expect($leadHtml)->toMatch('#data-yandex-direct-click>.*?'.preg_quote(__('admin.common.yes'), '#').'#s');

    $plain = Lead::factory()->create();
    $this->get(route('admin.leads.show', $plain))->assertSee(__('admin.common.no'));
});

describe('T6 Settings → Tracking help', function () {
    it('lists every goal and the UTM template once a Metrika ID is saved, without banned wording', function () {
        Setting::factory()->setting('tracking', ['enabled' => true, 'ga4' => '', 'metrika' => '98765432'])->create();

        $html = $this->actingAs(staffUser())->get(route('admin.settings.company', ['tab' => 'tracking']))->assertOk()->getContent();

        expect($html)->toContain('data-tracking-help')
            ->toContain(e('utm_source=yandex&utm_medium=cpc&utm_campaign={campaign_id}&utm_content={ad_id}&utm_term={keyword}'));

        foreach (AnalyticsGoal::cases() as $goal) {
            expect($html)->toContain('data-goal="'.$goal->value.'"');
        }
    });

    it('is hidden without a Metrika ID', function () {
        $this->actingAs(staffUser())->get(route('admin.settings.company', ['tab' => 'tracking']))->assertOk()->assertDontSee('data-tracking-help', false);
    });
});

it('YD-16: every new text exists in uz, ru and en, without price or discount wording', function () {
    $groups = ['analytics.campaigns', 'analytics.tracking_help', 'analytics.goals', 'enums.analytics_goal', 'checkout.placed'];
    $keys = null;

    foreach (['uz', 'ru', 'en'] as $locale) {
        $texts = [];

        foreach ($groups as $group) {
            $value = trans($group, [], $locale);
            expect($value)->toBeArray();
            $texts += collect(Arr::dot($value))->mapWithKeys(fn ($text, $key) => [$group.'.'.$key => $text])->all();
        }

        $keys ??= array_keys($texts);
        expect(array_keys($texts))->toBe($keys);
        expectNoBannedWording(mb_strtolower(implode("\n", $texts)));

        if ($locale === 'uz') {
            expect(implode("\n", $texts))->not->toMatch("/[a-z]['‘’][a-z]/i");
        }
    }
});

it('counts an accepted quote without a lead in the (none) group', function () {
    Quote::factory()->status(QuoteStatus::Accepted)->totalling(2_500_000_00)->create(['lead_id' => null]);

    $none = collect((new CampaignReport(30))->build()['rows'])->firstWhere('source', CampaignReport::NONE);

    expect($none['quotes'])->toBe(1)
        ->and($none['quotes_value'])->toBe(2_500_000_00);

    expect((new CampaignReport(30, directOnly: true))->build()['rows'])->toBe([]);
});

it('shows the last export end as dd.mm.yyyy under the date range', function () {
    Setting::query()->create(['key' => CompanySettings::AD_CLICK_IDS_KEY, 'group' => 'seo', 'value' => true]);
    Setting::query()->create(['key' => ExportOfflineConversions::LAST_EXPORT_KEY, 'group' => 'seo', 'value' => '2026-11-20']);
    CompanySettings::flush();

    $this->actingAs(staffUser())->get(route('admin.analytics.campaigns'))->assertOk()
        ->assertSee(__('analytics.campaigns.export.last', ['date' => '20.11.2026']));
});

it('rejects a tampered switch value without an error page or a change', function (mixed $value) {
    $this->actingAs(staffUser());
    confirmAdminPassword();

    Livewire::test(Campaigns::class)->set('clickIdsEnabled', $value)->call('saveClickIds')->assertHasErrors('clickIdsEnabled');

    expect(Setting::query()->where('key', CompanySettings::AD_CLICK_IDS_KEY)->exists())->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'string' => ['abc'],
    'array' => [[true]],
    'number' => [5],
]);
