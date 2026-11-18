<?php

use App\Actions\Repairs\GenerateRepairReceiptPdf;
use App\Actions\Repairs\RotateRepairStatusToken;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Exceptions\QrUnavailableException;
use App\Models\Activity;
use App\Models\Repair;
use App\Models\User;
use App\Support\Qr\QrRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Plan 35 section 10, abuse cases on the receipt PDF and the link rotation.
 *
 * Covered elsewhere:
 *  10 receipt: RepairReceiptTest "refuses content managers and colleagues engineers and writes no token"
 *     (content manager, colleague engineer; guest: "redirects a guest"); rotation below
 *  11 policy matrix: RepairStatusTokenTest "decides rotateStatusLink and printReceipt per role and repair";
 *     Sales 403 and the sixth rotation: "refuses a rotation the policy denies",
 *     "allows five rotations a day, the sixth fails"; token-unchanged checks below
 *  12 activity meta: RepairStatusTokenTest "rotates the token and logs it without the value"; old/new over HTTP below
 *  13 model: "never serializes the token"; Telegram, chatbot and MCP: RepairStatusAbuseTest
 *  14 HTML escaping: RepairReceiptTest "escapes user text and fetches nothing remote"; the PDF render itself below
 *  16 html(): RepairReceiptTest "fails closed without GD and never falls back to SVG"; pdf() below
 */
describe('10 and 11 rotation authorization', function () {
    it('refuses everyone but the owner of the repair and leaves the token alone', function (string $who, bool $closed) {
        $colleague = staffUser(Role::ServiceEngineer);
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
        $actor = match ($who) {
            'content' => User::factory()->withRole(Role::ContentManager)->create(),
            'sales' => User::factory()->withRole(Role::SalesManager)->create(),
            default => $engineer,
        };
        $factory = Repair::factory()->withStatusToken();
        $factory = $who === 'unassigned' ? $factory : $factory->assignedTo($colleague);
        $repair = ($closed ? $factory->closedDaysAgo(2) : $factory)->create();
        $token = $repair->public_token;

        expect(fn () => app(RotateRepairStatusToken::class)->handle($repair, $actor))->toThrow(AuthorizationException::class);

        expect($repair->fresh()->public_token)->toBe($token)
            ->and(Activity::query()->where('type', ActivityType::Repair->value)->count())->toBe(0);
    })->with([
        'content manager' => ['content', false],
        'sales manager' => ['sales', false],
        'colleague engineer' => ['colleague', false],
        'engineer on an unassigned closed repair' => ['unassigned', true],
    ]);

    it('keeps the current token when the sixth rotation of the day is refused', function () {
        $admin = staffUser(Role::Admin);
        $repair = Repair::factory()->withStatusToken()->create();

        foreach (range(1, RotateRepairStatusToken::DAILY_LIMIT) as $i) {
            app(RotateRepairStatusToken::class)->handle($repair, $admin);
        }
        $token = $repair->fresh()->public_token;

        expect(fn () => app(RotateRepairStatusToken::class)->handle($repair, $admin))->toThrow(ValidationException::class);

        expect($repair->fresh()->public_token)->toBe($token);
        $this->get('/r/'.$token)->assertOk();
    });

    it('allows five more rotations the next Tashkent day', function () {
        $admin = staffUser(Role::Admin);
        $repair = Repair::factory()->withStatusToken()->create();

        foreach (range(1, RotateRepairStatusToken::DAILY_LIMIT) as $i) {
            app(RotateRepairStatusToken::class)->handle($repair, $admin);
        }

        $this->travel(1)->days();
        app(RotateRepairStatusToken::class)->handle($repair, $admin);

        expect(Activity::query()->where('meta->action', 'status_link_rotated')->count())->toBe(6);
    });
});

describe('12 rotation over HTTP', function () {
    it('kills the old link at once, serves the new one and keeps the token out of the log', function () {
        $admin = staffUser(Role::Admin);
        $repair = Repair::factory()->withStatusToken()->create();
        $old = $repair->public_token;
        $this->get('/r/'.$old)->assertOk();

        app(RotateRepairStatusToken::class)->handle($repair, $admin);
        $new = $repair->fresh()->public_token;

        $this->get('/r/'.$old)->assertNotFound();
        $this->get('/r/'.$new)->assertOk();

        $dump = json_encode([Activity::query()->get()->toArray(), $repair->fresh()->only(['number', 'status', 'closed_at'])]);
        expect($dump)->not->toContain($old)->not->toContain($new);
    });

    it('does not revive a closed repair past its thirty days', function () {
        $admin = staffUser(Role::Admin);
        $repair = Repair::factory()->withStatusToken()->closedDaysAgo(31)->create();

        app(RotateRepairStatusToken::class)->handle($repair, $admin);

        $this->get('/r/'.$repair->fresh()->public_token)->assertNotFound();
    });

    it('does not put the token into the print log or a rotation snapshot', function () {
        $admin = staffUser(Role::Admin);
        $repair = Repair::factory()->withStatusToken()->create();
        $old = $repair->public_token;

        $this->actingAs($admin)->get(route('admin.repairs.receipt', $repair))->assertOk();
        app(RotateRepairStatusToken::class)->handle($repair, $admin);

        expect(json_encode(Activity::query()->get()->toArray()))
            ->not->toContain($old)
            ->not->toContain($repair->fresh()->public_token);
    });
});

describe('14 the PDF render fetches and executes nothing', function () {
    it('renders hostile intake text into a PDF with remote fetching, PHP and JS disabled and a pinned chroot', function () {
        $repair = Repair::factory()->withStatusToken()->create([
            'problem' => '<img src=http://evil.example/x.png><link rel=stylesheet href=http://evil.example/a.css><style>@import url(http://evil.example/b.css)</style><?php echo 1; ?>',
            'accessories' => '<iframe src=http://evil.example></iframe><script>app.alert(1)</script>',
            'intake_condition' => '<img src="file:///etc/passwd">',
        ]);

        $pdf = app(GenerateRepairReceiptPdf::class)->pdf($repair);
        $options = $pdf->getDomPDF()->getOptions();

        expect($pdf->output())->toStartWith('%PDF')
            ->and($options->getIsRemoteEnabled())->toBeFalse()
            ->and($options->getIsPhpEnabled())->toBeFalse()
            ->and($options->getIsJavascriptEnabled())->toBeFalse()
            ->and(implode('|', (array) $options->getChroot()))->toContain(resource_path('pdf'));
    });

    it('streams the same hardened PDF over HTTP', function () {
        Http::preventStrayRequests();
        $repair = Repair::factory()->withStatusToken()->create(['problem' => '<img src=http://evil.example/x.png>']);

        $this->actingAs(staffUser())->get(route('admin.repairs.receipt', $repair))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    });
});

describe('16 no GD', function () {
    it('fails closed when the PDF is built and never emits an SVG', function () {
        $this->mock(QrRenderer::class, function ($mock) {
            $mock->shouldReceive('pngDataUri')->andReturnNull();
            $mock->shouldNotReceive('svgDataUri');
        });
        $repair = Repair::factory()->withStatusToken()->create();
        $action = app(GenerateRepairReceiptPdf::class);

        expect(fn () => $action->pdf($repair))->toThrow(QrUnavailableException::class)
            ->and(fn () => $action->stream($repair))->toThrow(QrUnavailableException::class)
            ->and(fn () => $action->html($repair))->toThrow(QrUnavailableException::class);
    });

    it('never contains an svg element in a normal receipt', function () {
        $repair = Repair::factory()->withStatusToken()->create();

        $html = app(GenerateRepairReceiptPdf::class)->html($repair);

        expect($html)->not->toContain('<svg')->not->toContain('image/svg+xml')
            ->and($html)->toContain('data:image/png;base64,');
    });
});
