<?php

use App\Actions\Analytics\ExportOfflineConversions;
use App\Enums\AnalyticsGoal;
use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\CompanySettings;
use Illuminate\Support\Facades\Log;

/**
 * Plan 40 T5: the offline-conversion CSV (YD-10, YD-11, YD-12, YD-16).
 */
function ydExportOn(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => CompanySettings::AD_CLICK_IDS_KEY], ['group' => 'seo', 'value' => $on]);
    CompanySettings::flush();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ydExportInput(array $overrides = []): array
{
    $today = ExportOfflineConversions::today();

    return array_replace([
        'from' => $today->subDays(7)->toDateString(),
        'to' => $today->toDateString(),
        'id_type' => 'client_id',
        'goals' => ['order_paid', 'quote_accepted'],
    ], $overrides);
}

/**
 * @return list<list<string>>
 */
function ydCsvRows(string $csv): array
{
    return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", $csv))));
}

beforeEach(fn () => $this->travelTo(now('Asia/Tashkent')->setTime(12, 0)->utc()));

describe('YD-10 access', function () {
    it('lets only the Admin download, by POST with CSRF', function (Role $role, int $status) {
        ydExportOn();

        $this->actingAs(staffUser($role))->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertStatus($status);
    })->with([
        'admin' => [Role::Admin, 200],
        'content manager' => [Role::ContentManager, 403],
        'sales manager' => [Role::SalesManager, 403],
        'service engineer' => [Role::ServiceEngineer, 403],
    ]);

    it('redirects guests, rejects a deactivated admin and has no GET', function () {
        ydExportOn();
        $this->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertRedirect(route('admin.login'));

        $admin = staffUser();
        $admin->forceFill(['is_active' => false])->save();
        $response = $this->actingAs($admin)->post(route('admin.analytics.offline-conversions'), ydExportInput());
        expect($response->status())->not->toBe(200);

        $this->actingAs(staffUser())->get('/admin/analytics/offline-conversions.csv')->assertStatus(405);
    });

    it('is refused while the ad click id switch is off', function () {
        ydExportOn(false);

        $this->actingAs(staffUser())->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertForbidden();
    });

    it('sends an attachment with a fixed name and no-store / nosniff, throttled at 10 a minute', function () {
        ydExportOn();
        $this->actingAs(staffUser());

        $response = $this->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertOk();

        expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=offline-conversions.csv')
            ->and($response->headers->get('Content-Type'))->toStartWith('text/csv')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

        foreach (range(2, 10) as $ignored) {
            $this->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertOk();
        }

        $this->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertStatus(429);
    });

    it('needs the CSRF token (the route is in the web group)', function () {
        ydExportOn();
        $route = app('router')->getRoutes()->getByName('admin.analytics.offline-conversions');

        expect($route->methods())->toBe(['POST'])
            ->and($route->gatherMiddleware())->toContain('web', 'can:analytics.export', 'throttle:10,1', 'admin');
    });
});

describe('YD-11 rows', function () {
    beforeEach(function () {
        ydExportOn();

        // Paid orders: one with both ids, one with a yclid only, one with nothing.
        Order::factory()->totalling(1_250_000_50)->paid()->create(['yclid' => '111', 'ym_client_id' => '1700000000000000001', 'name' => 'Aziz Secret', 'phone' => '+998901112233']);
        Order::factory()->totalling(2_000_000_00)->paid()->create(['yclid' => '222', 'ym_client_id' => null]);
        Order::factory()->totalling(3_000_000_00)->paid()->create();
        // Excluded: TEST, refunded, unpaid, paid before the range.
        Order::factory()->test()->totalling(9_000_000_00)->paid()->create(['ym_client_id' => '1700000000000000009', 'yclid' => '999']);
        Order::factory()->totalling(8_000_000_00)->create(['payment_status' => PaymentStatus::Refunded, 'paid_at' => now(), 'ym_client_id' => '1700000000000000008']);
        Order::factory()->totalling(7_000_000_00)->create(['ym_client_id' => '1700000000000000007']);
        Order::factory()->totalling(6_000_000_00)->paid()->create(['paid_at' => now()->subDays(30), 'ym_client_id' => '1700000000000000006']);

        // Quotes: accepted (counted, dealer price level allowed), accepted-then-rejected (excluded).
        $lead = Lead::factory()->create(['yclid' => '333', 'ym_client_id' => '1700000000000000003']);
        Quote::factory()->status(QuoteStatus::Accepted)->totalling(4_000_000_00)->create(['lead_id' => $lead->id, 'number' => 'Q-2026-7777']);
        Quote::factory()->status(QuoteStatus::Rejected)->totalling(5_000_000_00)->create(['lead_id' => $lead->id, 'accepted_at' => now()]);
        // A formula-looking id can never be stored, but a hand-edited row is skipped anyway.
        $tampered = Lead::factory()->create();
        Lead::query()->whereKey($tampered->id)->toBase()->update(['ym_client_id' => '=HYPERLINK("x")']);
        Quote::factory()->status(QuoteStatus::Accepted)->totalling(1_000_00)->create(['lead_id' => $tampered->id]);
    });

    it('builds the ClientId file: exact rows, numeric cells, no PII', function () {
        $csv = $this->actingAs(staffUser())->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertOk()->getContent();
        $rows = ydCsvRows($csv);

        expect($rows[0])->toBe(['ClientId', 'Target', 'DateTime', 'Price', 'Currency'])
            ->and(array_slice($rows, 1))->toEqualCanonicalizing([
                ['1700000000000000001', 'order_paid', (string) now()->getTimestamp(), '1250000.50', 'UZS'],
                ['1700000000000000003', 'quote_accepted', (string) now()->getTimestamp(), '4000000', 'UZS'],
            ])
            ->and($csv)->not->toContain('HYPERLINK')->not->toContain('Aziz')->not->toContain('+998')->not->toContain('Q-2026')->not->toContain('O-20');
    });

    it('builds the Yclid file only from rows without a ClientId (dedupe)', function () {
        $csv = $this->actingAs(staffUser())->post(route('admin.analytics.offline-conversions'), ydExportInput(['id_type' => 'yclid']))->assertOk()->getContent();

        expect(array_slice(ydCsvRows($csv), 1))->toBe([['222', 'order_paid', (string) now()->getTimestamp(), '2000000', 'UZS']])
            ->and(ydCsvRows($csv)[0][0])->toBe('Yclid');
    });

    it('exports only the chosen goals', function () {
        $csv = $this->actingAs(staffUser())->post(route('admin.analytics.offline-conversions'), ydExportInput(['goals' => ['quote_accepted']]))->getContent();

        expect(array_column(array_slice(ydCsvRows($csv), 1), 1))->toBe(['quote_accepted']);
    });

    it('drops quotes from the offer when the config leaves them out (YD-16)', function () {
        config(['analytics.offline_conversions.goals' => ['order_paid']]);

        $this->actingAs(staffUser())->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertStatus(422);
        expect(ExportOfflineConversions::allowedGoals())->toBe([AnalyticsGoal::OrderPaid]);
    });

    it('re-validates each cell', function (array $row, bool $valid) {
        expect(ExportOfflineConversions::validRow($row, 'client_id'))->toBe($valid);
    })->with([
        'ok' => [['1700000000000000001', 'order_paid', 1700000000, '10.50', 'UZS'], true],
        'formula id' => [['=1+1', 'order_paid', 1700000000, '10', 'UZS'], false],
        'online goal' => [['1700000000000000001', 'lead_callback', 1700000000, '10', 'UZS'], false],
        'string time' => [['1700000000000000001', 'order_paid', '1700000000', '10', 'UZS'], false],
        'bad price' => [['1700000000000000001', 'order_paid', 1700000000, '1,5', 'UZS'], false],
        'currency' => [['1700000000000000001', 'order_paid', 1700000000, '10', 'USD'], false],
    ]);
});

describe('YD-11 ranges', function () {
    it('answers 422 for an invalid range or input', function (Closure $input) {
        ydExportOn();

        $this->actingAs(staffUser())->post(route('admin.analytics.offline-conversions'), $input())->assertStatus(422);
        expect(SettingsAudit::query()->count())->toBe(0);
    })->with([
        'from after to' => [fn () => ydExportInput(['from' => ExportOfflineConversions::today()->toDateString(), 'to' => ExportOfflineConversions::today()->subDay()->toDateString()])],
        'more than 21 days back' => [fn () => ydExportInput(['from' => ExportOfflineConversions::today()->subDays(22)->toDateString()])],
        'future' => [fn () => ydExportInput(['to' => ExportOfflineConversions::today()->addDay()->toDateString()])],
        'bad format' => [fn () => ydExportInput(['from' => '24.11.2026'])],
        'unknown id type' => [fn () => ydExportInput(['id_type' => 'gclid'])],
        'unknown goal' => [fn () => ydExportInput(['goals' => ['lead_callback']])],
        'no goals' => [fn () => ydExportInput(['goals' => []])],
    ]);

    it('accepts exactly 21 days back and remembers the last export end', function () {
        ydExportOn();
        $from = ExportOfflineConversions::today()->subDays(21)->toDateString();

        $this->actingAs(staffUser())->post(route('admin.analytics.offline-conversions'), ydExportInput(['from' => $from]))->assertOk();

        expect(ExportOfflineConversions::lastExportUntil())->toBe(ExportOfflineConversions::today()->toDateString())
            ->and(ExportOfflineConversions::defaultFrom())->toBe(ExportOfflineConversions::today()->toDateString());
    });
});

it('YD-12: audits who, when, range, identifier type and rows; never ids or amounts', function () {
    ydExportOn();
    Order::factory()->totalling(1_250_000_00)->paid()->create(['ym_client_id' => '1700000000000000001', 'yclid' => '111']);
    Log::spy();
    $admin = staffUser();

    $this->actingAs($admin)->post(route('admin.analytics.offline-conversions'), ydExportInput())->assertOk();

    $audit = SettingsAudit::query()->where('action', AuditAction::Exported->value)->sole();
    $range = ExportOfflineConversions::today()->subDays(7)->toDateString().'_'.ExportOfflineConversions::today()->toDateString();

    expect($audit->area)->toBe(SettingsArea::Analytics)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->key)->toBe('offline_export.client_id.'.$range.'.rows_1')
        ->and($audit->key)->not->toContain('1700000000000000001')->not->toContain('1250000');

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => $message === 'Offline conversions exported'
        && $context['rows'] === 1 && $context['user_id'] === $admin->id
        && ! str_contains(json_encode($context), '1700000000000000001') && ! str_contains(json_encode($context), '1250000'));
});
