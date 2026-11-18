<?php

require_once __DIR__.'/../Chat/ChatBotHelpers.php';

use App\Actions\Chat\LookupRepairStatus;
use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Models\TelegramUser;
use App\Support\Site\CanonicalHost;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * Plan 35 section 10, abuse cases on the public status page /r/{token}.
 *
 * Covered elsewhere (tests/Feature/Site/RepairStatusPageTest.php):
 *  1 "rejects a case-flipped token"
 *  2 "answers every kind of miss with a byte-identical 404"
 *  3 "serves open and recently closed repairs, with the private headers"
 *  4 "never shows private repair data for any status"
 *  5 "strips long digit runs from the device line and escapes markup"
 *  6 cookie half: "sets no cookie and starts no session"; session half below
 *  7 "loads no foreign host"
 *  8 "throttles the 21st request in a minute"
 *  9 URL half: RepairStatusTokenTest "builds the status url on the APP_URL host, not the request host"
 *    and RepairReceiptTest "builds the QR url on the canonical host even from another host"; www/foreign half below
 * 13 model half: RepairStatusTokenTest "never serializes the token"; MCP half: McpTelegramExclusionTest
 *    (Repair is a forbidden class for App\Mcp); chatbot and Telegram halves below
 * 15 headers: "serves open and recently closed repairs, with the private headers"; lang fallback and disk below
 */
defined('CB_PHONE') || define('CB_PHONE', '+998901234567');

function abuseTokenRepair(): Repair
{
    return Repair::factory()->withStatusToken()->create();
}

/**
 * @return list<string> every file under the paths a request could write to
 */
function abuseWrittenFiles(): array
{
    $files = [];

    foreach ([storage_path('app'), storage_path('framework/sessions'), storage_path('framework/cache/data')] as $dir) {
        if (is_dir($dir)) {
            foreach (File::allFiles($dir) as $file) {
                $files[] = $file->getPathname().'|'.$file->getMTime();
            }
        }
    }

    sort($files);

    return $files;
}

describe('6 no session', function () {
    it('creates no session row and no session file on a hit or a miss', function () {
        config(['session.driver' => 'database']);
        app()->forgetInstance('session');
        app()->forgetInstance('session.store');
        $repair = abuseTokenRepair();
        $before = abuseWrittenFiles();

        $this->get('/r/'.$repair->public_token)->assertOk();
        $this->get('/r/'.Str::random(40))->assertNotFound();

        expect(DB::table('sessions')->count())->toBe(0)
            ->and(abuseWrittenFiles())->toBe($before)
            ->and(app('session.store')->isStarted())->toBeFalse();
    });
});

describe('9 canonical host', function () {
    it('trusts the APP_URL host and its www twin only', function (string $appUrl, string $host, bool $trusted) {
        config(['app.url' => $appUrl]);

        $matches = collect(CanonicalHost::trustedHosts())
            ->contains(fn (string $pattern): bool => preg_match('{'.$pattern.'}i', $host) === 1);

        expect($matches)->toBe($trusted);
    })->with([
        'apex' => ['https://poson.example', 'poson.example', true],
        'www twin' => ['https://poson.example', 'www.poson.example', true],
        'www app url, bare twin' => ['https://www.poson.example', 'poson.example', true],
        'foreign' => ['https://poson.example', 'evil.example', false],
        'suffix trick' => ['https://poson.example', 'poson.example.evil.example', false],
        'prefix trick' => ['https://poson.example', 'evilposon.example', false],
        'other subdomain' => ['https://poson.example', 'a.poson.example', false],
    ]);

    it('puts the APP_URL host in the status url when the page is requested on the www twin', function () {
        config(['app.url' => 'https://poson.example']);
        $repair = abuseTokenRepair();

        $this->get('http://www.poson.example/r/'.$repair->public_token)->assertOk();

        expect($repair->statusUrl())->toStartWith('https://poson.example/r/')
            ->not->toContain('www.');
    });

    it('rejects a foreign Host in production and lets the apex and www twin through', function (string $host, bool $allowed) {
        config(['app.url' => 'https://poson.example']);
        $previousEnv = app()['env'];
        app()['env'] = 'production';

        try {
            $middleware = new TrustHosts(app());
            $middleware->handle(Request::create('https://'.$host.'/r/x'), fn () => response('ok'));

            $outcome = fn () => Request::create('https://'.$host.'/r/x')->getHost();

            if ($allowed) {
                expect($outcome())->toBe($host);
            } else {
                expect($outcome)->toThrow(SuspiciousOperationException::class);
            }
        } finally {
            Request::setTrustedHosts([]);
            app()['env'] = $previousEnv;
        }
    })->with([
        'apex' => ['poson.example', true],
        'www twin' => ['www.poson.example', true],
        'foreign' => ['evil.example', false],
        'suffix trick' => ['poson.example.evil.example', false],
    ]);

    it('builds the language links without a host', function () {
        $repair = abuseTokenRepair();

        $html = $this->get('/r/'.$repair->public_token)->getContent();

        expect($html)->toContain('href="/r/'.$repair->public_token.'?lang=')
            ->not->toMatch('#href="https?://[^"]*/r/#');
    });
});

describe('13 the token never leaves the status flow', function () {
    it('is absent from the Telegram bot reply and the chatbot repair lookup', function () {
        cbSetup();
        cbUser();
        $repair = Repair::factory()->forPhone(CB_PHONE)->withStatusToken()->status(RepairStatus::Ready)->create(['number' => 'R-2026-000321']);

        cbText('R-2026-000321');
        $reply = cbLast();

        expect($reply)->toContain('R-2026-000321')
            ->not->toContain($repair->public_token)
            ->not->toContain('/r/');

        $byId = app(LookupRepairStatus::class)->byId(TelegramUser::query()->firstOrFail(), $repair->id, 'en');
        expect(json_encode($byId, JSON_UNESCAPED_SLASHES))->not->toContain($repair->public_token);
    });

    it('is not a column the chatbot lookup reads', function () {
        expect(LookupRepairStatus::COLUMNS)->not->toContain('public_token')->not->toContain('public_token_rotated_at');
    });

    it('is referenced only by the status flow, never by Telegram, chat, MCP or notification code', function () {
        $allowed = [
            'app/Models/Repair.php',
            'app/Http/Controllers/Site/RepairStatusController.php',
            'app/Actions/Repairs/GenerateRepairReceiptPdf.php',
            'app/Actions/Repairs/RotateRepairStatusToken.php',
            'app/Actions/Repairs/EnsureRepairStatusToken.php',
            'app/Support/Repairs/RepairPublicStatus.php',
            // Staff-only admin screen (rotation UI), authorized by the policy.
            'app/Livewire/Admin/Repairs/Show.php',
        ];
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

            if (! in_array($relative, $allowed, true) && preg_match('/public_token|statusUrl\(/', $file->getContents()) === 1) {
                $offenders[] = $relative;
            }
        }

        expect($offenders)->toBe([]);
    });
});

describe('15 language and disk', function () {
    it('falls back to the repair locale for an invalid lang and never reflects it', function (string $lang) {
        $repair = Repair::factory()->withStatusToken()->create(['locale' => 'ru']);

        $response = $this->get('/r/'.$repair->public_token.'?lang='.urlencode($lang))->assertOk();

        expect($response->getContent())->toContain('lang="ru"')
            ->not->toContain('<script>alert')
            ->not->toContain('../');
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    })->with(['xx', '<script>alert(1)</script>', '../../etc/passwd', 'UZ', str_repeat('a', 300)]);

    it('treats an array lang as absent', function () {
        $repair = Repair::factory()->withStatusToken()->create(['locale' => 'en']);

        $this->get('/r/'.$repair->public_token.'?lang[]=ru')->assertOk()->assertSee('lang="en"', false);
    });

    it('writes nothing to disk on a hit or a miss', function () {
        $repair = abuseTokenRepair();
        $this->get('/r/'.$repair->public_token)->assertOk();
        $before = abuseWrittenFiles();

        $this->get('/r/'.$repair->public_token)->assertOk();
        $this->get('/r/'.Str::random(40))->assertNotFound();

        expect(abuseWrittenFiles())->toBe($before);
    });
});
