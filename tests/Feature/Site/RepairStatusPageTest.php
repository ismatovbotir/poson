<?php

use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Models\User;
use App\Support\Money;
use App\Support\Repairs\RepairPublicStatus;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Str;

function repairStatusPage(Repair $repair, string $lang = 'en')
{
    return test()->get('/r/'.$repair->public_token.'?lang='.$lang);
}

it('shows the right label and message for every status in every locale', function (RepairStatus $status, string $locale) {
    $repair = Repair::factory()->withStatusToken()->status($status)->create();
    $message = match ($status) {
        RepairStatus::Received, RepairStatus::Diagnosing => 'diagnostics',
        default => $status->value,
    };

    $response = repairStatusPage($repair, $locale)->assertOk();

    $html = $response->getContent();
    expect($html)->toContain(e(__('enums.repair_status.'.$status->value, [], $locale)))
        ->toContain(e(Str::before(__('repairs.public.message.'.$message, ['day' => '@'], $locale), '@')))
        ->toContain($repair->number);
})->with(fn () => collect(RepairStatus::cases())->flatMap(
    fn (RepairStatus $status) => collect(['uz', 'ru', 'en'])->mapWithKeys(fn (string $locale) => [$status->value.' '.$locale => [$status, $locale]])
)->all());

it('shows the ready-for-pickup block only when ready', function () {
    repairStatusPage(Repair::factory()->withStatusToken()->status(RepairStatus::Ready)->create())->assertSee('data-ready', false);
    repairStatusPage(Repair::factory()->withStatusToken()->status(RepairStatus::InRepair)->create())->assertDontSee('data-ready', false);
});

it('never shows private repair data for any status', function (RepairStatus $status) {
    $engineer = User::factory()->create(['name' => 'ZZENGINEERZZ']);
    $creator = User::factory()->create(['name' => 'ZZCREATORZZ']);
    $repair = Repair::factory()->withStatusToken()->status($status)->create([
        'contact_name' => 'ZZCONTACTNAMEZZ',
        'contact_phone' => '+998935550199',
        'company' => 'ZZCOMPANYZZ',
        'serial_number' => 'ZZSERIALZZ',
        'accessories' => 'ZZACCESSORIESZZ',
        'intake_condition' => 'ZZCONDITIONZZ',
        'problem' => 'ZZPROBLEMZZ',
        'diagnosis' => 'ZZDIAGNOSISZZ',
        'work_done' => 'ZZWORKZZ',
        'parts_used' => 'ZZPARTSZZ',
        'warranty_reference' => 'ZZWARRANTYZZ',
        'estimate_amount' => 12_345_600,
        'final_amount' => 23_456_700,
        'estimate_approved_amount' => 34_567_800,
        'assigned_to_id' => $engineer->id,
        'created_by_id' => $creator->id,
    ]);

    foreach (['uz', 'ru', 'en'] as $locale) {
        $html = repairStatusPage($repair, $locale)->assertOk()->getContent();

        foreach (['ZZCONTACTNAMEZZ', '935550199', '93 555', 'ZZCOMPANYZZ', 'ZZSERIALZZ', 'ZZACCESSORIESZZ', 'ZZCONDITIONZZ', 'ZZPROBLEMZZ', 'ZZDIAGNOSISZZ', 'ZZWORKZZ', 'ZZPARTSZZ', 'ZZWARRANTYZZ', 'ZZENGINEERZZ', 'ZZCREATORZZ', Money::format(12_345_600), Money::format(23_456_700), Money::format(34_567_800)] as $forbidden) {
            expect($html)->not->toContain($forbidden);
        }
    }
})->with(fn () => collect(RepairStatus::cases())->mapWithKeys(fn (RepairStatus $status) => [$status->value => [$status]])->all());

it('strips long digit runs from the device line and escapes markup', function () {
    $repair = Repair::factory()->withStatusToken()->create(['device_model' => 'Call 998901234567 <script>alert(1)</script>']);

    $html = repairStatusPage($repair)->assertOk()->getContent();

    expect($html)->not->toMatch('/\d{7,}/')
        ->not->toContain('<script>alert')
        ->toContain('&lt;script&gt;');

    $phone = Repair::factory()->withStatusToken()->create(['device_model' => 'Call 99890 123 45 67']);
    expect(repairStatusPage($phone)->getContent())->not->toContain('123 45 67');
});

it('caps the device line at 60 characters', function () {
    $repair = Repair::factory()->withStatusToken()->create(['device_model' => str_repeat('A', 200)]);

    expect(mb_strlen(RepairPublicStatus::deviceLine($repair, 'en')))->toBeLessThanOrEqual(60);
});

it('answers every kind of miss with a byte-identical 404', function () {
    $live = Repair::factory()->withStatusToken()->create();
    $rotated = Repair::factory()->withStatusToken()->create();
    $old = $rotated->public_token;
    $rotated->forceFill(['public_token' => Str::random(40)])->save();
    $expired = Repair::factory()->withStatusToken()->closedDaysAgo(31)->create();

    $paths = [
        '/r/'.Str::random(40),
        '/r/'.substr($live->public_token, 0, 39),
        '/r/'.$live->public_token.'A',
        '/r/'.Str::random(200),
        '/r/'.str_repeat('!', 40),
        '/r/'.$old,
        '/r/'.$expired->public_token,
        '/r/'.strtolower($live->public_token) === '/r/'.$live->public_token ? '/r/'.strtoupper($live->public_token) : '/r/'.strtolower($live->public_token),
    ];

    // The Vite helper remembers preloaded assets per instance; a fresh request in production starts clean.
    $fresh = fn (string $path) => tap($this->get($path), fn () => app()->forgetInstance(Vite::class));

    // Livewire adds data-navigate-track to the first stylesheet tag of the process only.
    $normalize = fn (string $html): string => str_replace(' data-navigate-track="reload"', '', $html);

    $first = $fresh($paths[0])->assertNotFound();

    foreach ($paths as $path) {
        $response = $fresh($path)->assertNotFound();
        expect($normalize($response->getContent()))->toBe($normalize($first->getContent()));
        foreach (['X-Robots-Tag', 'Referrer-Policy'] as $header) {
            expect($response->headers->get($header))->toBe($first->headers->get($header));
        }
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('rejects a case-flipped token', function () {
    $repair = Repair::factory()->withStatusToken()->create();
    $flipped = strtr($repair->public_token, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');

    $this->get('/r/'.$flipped)->assertNotFound();
});

it('serves open and recently closed repairs, with the private headers', function () {
    $open = Repair::factory()->withStatusToken()->create();
    $closed = Repair::factory()->withStatusToken()->closedDaysAgo(29)->create();

    $response = repairStatusPage($open)->assertOk();
    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    repairStatusPage($closed)->assertOk();
});

it('sets no cookie and starts no session', function () {
    $repair = Repair::factory()->withStatusToken()->create();

    $ok = repairStatusPage($repair)->assertOk();
    $miss = $this->get('/r/'.Str::random(40))->assertNotFound();

    expect($ok->headers->getCookies())->toBeEmpty()
        ->and($miss->headers->getCookies())->toBeEmpty()
        ->and($ok->headers->get('Set-Cookie'))->toBeNull();
});

it('loads no foreign host', function () {
    $repair = Repair::factory()->withStatusToken()->create();
    // The app's own origin as the framework resolves it (APP_URL based, not env-specific in the test).
    $parts = parse_url(url('/'));
    $own = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

    foreach ([repairStatusPage($repair)->getContent(), $this->get('/r/short')->getContent()] as $html) {
        preg_match_all('#https?://([^/"\'\s]+)#i', $html, $matches);
        foreach ($matches[1] as $host) {
            // The company's own Telegram link (settings) is the only allowed external anchor.
            expect($host)->toBeIn([$own, 't.me']);
        }
        // Livewire's static "rendered a component" flag can leak in from an earlier test in the same
        // process and auto-inject its asset tag; the page itself has no component, so ignore that tag.
        expect(preg_replace('#<script src="[^"]*livewire[^"]*"[^>]*></script>#', '', $html))->not->toContain('<script');
    }
});

it('throttles the 21st request in a minute', function () {
    $repair = Repair::factory()->withStatusToken()->create();

    foreach (range(1, 20) as $i) {
        $this->get('/r/'.$repair->public_token)->assertOk();
    }

    $this->get('/r/'.$repair->public_token)->assertTooManyRequests();
});

it('disallows /r/ in robots.txt and excludes it from analytics', function () {
    openSearchIndexing();

    expect($this->get('/robots.txt')->getContent())->toContain('Disallow: /r/')
        ->and(config('analytics.exclude_paths'))->toContain('r/*');
});

it('keeps the device line real model names and strips phone numbers', function (string $model, bool $kept) {
    $repair = Repair::factory()->make(['device_brand' => null, 'device_model' => $model]);

    $line = RepairPublicStatus::deviceLine($repair, 'en');

    if ($kept) {
        expect($line)->toContain($model);
    } else {
        expect($line)->not->toMatch('/\d{7,}/')
            ->not->toContain('123 45 67')->not->toContain('123-45')->not->toContain('9012');
    }
})->with([
    'model + year' => ['TE210 300 2024', true],
    'brand + number + year' => ['CipherLab 1500 2024', true],
    'model + 1D + year' => ['MC3300 1D 2023', true],
    'spaced phone' => ['+998 90 123 45 67', false],
    'dashed phone' => ['90-123-45-67', false],
    'bracketed phone' => ['(90) 123 45 67', false],
    'plain digits' => ['998901234567', false],
    'glued to letters' => ['tel998901234567', false],
]);

it('runs a constant, small number of queries for a hit and a miss', function () {
    $repair = Repair::factory()->withStatusToken()->create();
    $this->get('/r/'.$repair->public_token)->assertOk();

    $hit = countQueries(fn () => $this->get('/r/'.$repair->public_token));
    $miss = countQueries(fn () => $this->get('/r/'.Str::random(40)));
    $malformed = countQueries(fn () => $this->get('/r/short'));

    expect($hit)->toBeLessThanOrEqual(3)
        ->and($miss)->toBeLessThanOrEqual(3)
        ->and($malformed)->toBeLessThanOrEqual($miss);

    Repair::factory()->withStatusToken()->count(5)->create();
    expect(countQueries(fn () => $this->get('/r/'.$repair->public_token)))->toBe($hit);
});
