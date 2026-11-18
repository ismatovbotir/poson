<?php

use App\Actions\Repairs\GenerateRepairReceiptPdf;
use App\Enums\ActivityType;
use App\Enums\RepairCoverage;
use App\Enums\Role;
use App\Exceptions\QrUnavailableException;
use App\Models\Activity;
use App\Models\Repair;
use App\Models\User;
use App\Support\Money;
use App\Support\Qr\QrRenderer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * Plan 35 I2: the printable repair receipt (P35-6 … P35-9).
 */
beforeEach(function () {
    Cache::flush();
});

function receiptUrl(Repair $repair, ?string $lang = null): string
{
    return route('admin.repairs.receipt', array_filter(['repair' => $repair, 'lang' => $lang]));
}

it('streams the PDF for roles that may view the repair', function (string $who) {
    $engineer = staffUser(Role::ServiceEngineer);
    [$user, $repair] = match ($who) {
        'admin' => [staffUser(Role::Admin), Repair::factory()->create()],
        'sales' => [staffUser(Role::SalesManager), Repair::factory()->create()],
        'assigned' => [$engineer, Repair::factory()->assignedTo($engineer)->create()],
        'unassigned' => [$engineer, Repair::factory()->create(['assigned_to_id' => null])],
    };

    $response = $this->actingAs($user)->get(receiptUrl($repair));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
})->with(['admin', 'sales', 'assigned', 'unassigned']);

it('refuses content managers and colleagues engineers and writes no token', function (string $who) {
    $engineer = staffUser(Role::ServiceEngineer);
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
    [$user, $repair] = match ($who) {
        'content' => [staffUser(Role::ContentManager), Repair::factory()->create()],
        'colleague' => [$engineer, Repair::factory()->assignedTo($colleague)->create()],
    };

    $this->actingAs($user)->get(receiptUrl($repair))->assertForbidden();

    expect($repair->fresh()->public_token)->toBeNull();
})->with(['content', 'colleague']);

it('redirects a guest', function () {
    $repair = Repair::factory()->create();

    $this->get(receiptUrl($repair))->assertRedirect();
    expect($repair->fresh()->public_token)->toBeNull();
});

it('shows the intake data and the status URL but never costs, diagnosis, work, parts or the engineer', function (string $case) {
    $engineer = User::factory()->create(['name' => 'Engineer Zafarbek']);
    $factory = Repair::factory()->withStatusToken()->assignedTo($engineer);
    $factory = match ($case) {
        'paid+estimate' => $factory->estimated(15_000_000),
        'paid' => $factory,
        'warranty' => $factory->warranty('INV-777-REF')->estimated(12_300_000),
    };
    $repair = $factory->create([
        'diagnosis' => 'DIAGNOSIS-MARKER',
        'work_done' => 'WORKDONE-MARKER',
        'parts_used' => 'PARTS-MARKER',
        'final_amount' => 9_870_000,
        'estimate_approved_amount' => 15_000_000,
    ]);

    $html = app(GenerateRepairReceiptPdf::class)->html($repair);

    expect($html)->toContain($repair->number)
        ->toContain($repair->contact_name)
        ->toContain($repair->serial_number)
        ->toContain('DS2208')
        ->toContain($repair->problem)
        ->toContain(RepairCoverage::from($repair->coverage->value)->label())
        ->toContain($repair->statusUrl())
        ->not->toContain('DIAGNOSIS-MARKER')
        ->not->toContain('WORKDONE-MARKER')
        ->not->toContain('PARTS-MARKER')
        ->not->toContain('Engineer Zafarbek')
        ->not->toContain(Money::format(15_000_000))
        ->not->toContain(Money::format(12_300_000))
        ->not->toContain(Money::format(9_870_000));

    if ($case === 'warranty') {
        expect($html)->toContain('INV-777-REF');
    }
})->with(['paid+estimate', 'paid', 'warranty']);

it('renders both copies', function () {
    $repair = Repair::factory()->withStatusToken()->create();

    $html = app(GenerateRepairReceiptPdf::class)->html($repair, 'en');

    expect($html)->toContain('Client copy')->toContain('Service copy');
});

it('prints in the requested language and falls back to the repair locale', function () {
    $repair = Repair::factory()->create(['locale' => 'en']);
    $action = app(GenerateRepairReceiptPdf::class);

    expect($action->html($repair, 'ru'))->toContain('Акт приёма в ремонт')
        ->and($action->html($repair, 'xx'))->toContain('Repair intake receipt')
        ->and($action->html($repair))->toContain('Repair intake receipt');

    $admin = staffUser();
    $this->actingAs($admin)->get(receiptUrl($repair, 'xx'))->assertOk();
    expect(Activity::query()->where('type', ActivityType::Repair)->first()->meta['locale'])->toBe('en');
});

it('builds the QR url on the canonical host even from another host', function () {
    config(['app.url' => 'https://poson.example']);
    URL::useOrigin('https://www.poson.example');
    $repair = Repair::factory()->withStatusToken()->create();

    $html = app(GenerateRepairReceiptPdf::class)->html($repair);

    expect($html)->toContain('https://poson.example/r/'.$repair->public_token)
        ->not->toContain('www.');
});

it('creates the token on the first print of a legacy row', function () {
    $repair = Repair::factory()->create();
    expect($repair->public_token)->toBeNull();

    $this->actingAs(staffUser())->get(receiptUrl($repair))->assertOk();

    expect($repair->fresh()->public_token)->toMatch('/^[A-Za-z0-9]{40}$/');
});

it('logs a print once per user per repair in ten minutes', function () {
    $repair = Repair::factory()->create();
    $admin = staffUser();

    $this->actingAs($admin)->get(receiptUrl($repair))->assertOk();
    $this->actingAs($admin)->get(receiptUrl($repair))->assertOk();

    $rows = Activity::query()->where('type', ActivityType::Repair)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->meta)->toBe(['action' => 'receipt_printed', 'locale' => 'uz'])
        ->and(json_encode($rows->first()->meta))->not->toContain($repair->fresh()->public_token);
});

it('escapes user text and fetches nothing remote', function () {
    Http::preventStrayRequests();
    $repair = Repair::factory()->withStatusToken()->create([
        'problem' => '<img src=http://evil.example/x.png><style>body{display:none}</style>',
    ]);

    $html = app(GenerateRepairReceiptPdf::class)->html($repair);

    expect($html)->not->toContain('<img src=http://evil.example')
        ->not->toContain('<style>body{display:none}')
        ->toContain('&lt;img src=http://evil.example/x.png&gt;')
        ->toContain('&lt;style&gt;');

    $this->actingAs(staffUser())->get(receiptUrl($repair))->assertOk();
});

it('fails closed without GD and never falls back to SVG', function () {
    $this->mock(QrRenderer::class, function ($mock) {
        $mock->shouldReceive('pngDataUri')->andReturnNull();
        $mock->shouldNotReceive('svgDataUri');
    });
    $repair = Repair::factory()->withStatusToken()->create();

    $response = $this->actingAs(staffUser())->get(receiptUrl($repair));

    $response->assertStatus(500);
    expect($response->headers->get('Content-Type'))->not->toContain('pdf')
        ->and($response->getContent())->not->toContain('image/svg+xml');
    expect(fn () => app(GenerateRepairReceiptPdf::class)->html($repair))->toThrow(QrUnavailableException::class);
});

it('shows the localized error page when the QR cannot be drawn', function () {
    $this->mock(QrRenderer::class, fn ($mock) => $mock->shouldReceive('pngDataUri')->andReturnNull());
    $repair = Repair::factory()->withStatusToken()->create(['locale' => 'en']);

    $this->actingAs(staffUser(Role::Admin, 'en'))->get(receiptUrl($repair))
        ->assertStatus(500)
        ->assertSee(__('repairs.receipt.no_gd', [], 'en'));
});

it('replaces the QR with the expired line when the status link is no longer live', function () {
    $repair = Repair::factory()->withStatusToken()->closedDaysAgo(40)->create();

    $html = app(GenerateRepairReceiptPdf::class)->html($repair, 'en');

    expect($html)->toContain('data-link-expired')
        ->toContain('Status link expired')
        ->not->toContain('data:image/png;base64,')
        ->not->toContain('/r/'.$repair->public_token);
    expect(app(GenerateRepairReceiptPdf::class)->pdf($repair, 'en')->output())->toStartWith('%PDF');
    $this->actingAs(staffUser())->get(receiptUrl($repair))->assertOk();
});

it('renders a live receipt with the QR, a page-number footer and no raw overflow', function () {
    $repair = Repair::factory()->withStatusToken()->create(['serial_number' => str_repeat('A', 120)]);

    $html = app(GenerateRepairReceiptPdf::class)->html($repair, 'en');

    expect($html)->toContain('data:image/png;base64,')->toContain('page-number')
        ->not->toContain(str_repeat('A', 41))
        ->toContain("\u{200B}");
    expect(app(GenerateRepairReceiptPdf::class)->pdf($repair, 'en')->output())->toStartWith('%PDF');
});

it('signs the receiver line with the legal name from Settings, else Poson', function () {
    $repair = Repair::factory()->withStatusToken()->create();
    $action = app(GenerateRepairReceiptPdf::class);

    expect($action->html($repair, 'en'))->toContain('Received by (Poson)');

    sellerRequisites();

    expect($action->html($repair, 'en'))->toContain('Received by (Poson LLC)')
        ->and($action->html($repair, 'ru'))->toContain('Принял (ООО Poson)');
});
