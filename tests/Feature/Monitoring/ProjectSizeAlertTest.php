<?php

use App\Actions\Monitoring\MonitoringActionException;
use App\Actions\Monitoring\StartDiskMeasurement;
use App\Actions\Monitoring\UpdateDiskThresholds;
use App\Enums\AuditAction;
use App\Enums\DiskRunStatus;
use App\Enums\Role;
use App\Jobs\MeasureProjectSize;
use App\Models\MonitoringState;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\CompanySettings;
use App\Support\Monitoring\DiskRun;
use App\Support\Monitoring\DiskThresholds;
use App\Support\Monitoring\FolderSizer;
use App\Support\Monitoring\ProblemMonitor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

const GB = 1024 ** 3;

function sizeTelegram(bool $configured = true): void
{
    freshSecrets($configured ? [
        'services.telegram.bot_token' => '123456789:'.str_repeat('M', 35),
        'services.telegram.chats.management' => '-1009999999999',
    ] : []);

    sizeTelegramWorks(true);
    Http::fake(['api.telegram.org/*' => fn () => sizeTelegramWorks() ? Http::response(['ok' => true, 'result' => []]) : Http::response(['ok' => false], 500)]);
}

/**
 * Switch the faked Telegram between working and failing (the first Http::fake() wins).
 */
function sizeTelegramWorks(?bool $works = null): bool
{
    static $state = true;

    return $works === null ? $state : $state = $works;
}

/**
 * @return list<string> the text of every Telegram message sent
 */
function sizeMessages(): array
{
    return Http::recorded()->map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''))->values()->all();
}

function sizeState(): ?MonitoringState
{
    return MonitoringState::query()->find('problem:project_size');
}

function evaluateSize(int|float $gb, bool $partial = false): void
{
    (new ProblemMonitor)->evaluateProjectSize((int) round($gb * GB), $partial);
}

beforeEach(function () {
    sizeTelegram();
    $this->travelTo(now()->startOfHour()->addMinutes(5));
});

it('alerts at the warn limit and reminds hourly, then sends recovered once below 95 percent', function () {
    evaluateSize(5.2);
    expect(sizeMessages())->toHaveCount(1)
        ->and(sizeMessages()[0])->toContain(__('telegram.monitoring.events.project_size_warn', [], 'uz'))
        ->and(sizeState()->meta['level'])->toBe(0);

    $this->travel(30)->minutes();
    evaluateSize(5.3);
    expect(sizeMessages())->toHaveCount(1);

    $this->travel(31)->minutes();
    evaluateSize(5.3);
    expect(sizeMessages())->toHaveCount(2);

    // Still held between 95 % and 100 % of the limit.
    $this->travel(5)->minutes();
    evaluateSize(4.9);
    expect(sizeMessages())->toHaveCount(2)->and(sizeState()->problem_since)->not->toBeNull();

    evaluateSize(4.6);
    expect(sizeMessages())->toHaveCount(3)
        ->and(sizeMessages()[2])->toContain(__('telegram.monitoring.recovered.project_size', [], 'uz'))
        ->and(sizeState()->problem_since)->toBeNull();
});

it('sends nothing below the warn limit', function () {
    evaluateSize(3);

    expect(sizeMessages())->toBe([])->and(sizeState())->toBeNull();
});

it('escalates to critical at once and de-escalates silently below 95 percent of critical', function () {
    evaluateSize(5.5);
    $this->travel(2)->minutes();
    evaluateSize(8.1);

    expect(sizeMessages())->toHaveCount(2)
        ->and(sizeMessages()[1])->toContain(__('telegram.monitoring.events.project_size_critical', [], 'uz'))
        ->and(sizeMessages()[1])->not->toContain(__('telegram.monitoring.reminder', [], 'uz'))
        ->and(sizeState()->meta['level'])->toBe(1);

    $this->travel(2)->minutes();
    evaluateSize(7.9);
    expect(sizeMessages())->toHaveCount(2)->and(sizeState()->meta['level'])->toBe(1);

    evaluateSize(7.5);
    expect(sizeMessages())->toHaveCount(2)
        ->and(sizeState()->meta['level'])->toBe(0)
        ->and(sizeState()->problem_since)->not->toBeNull();
});

it('does not flap around the critical limit: at most 2 messages in an hour', function () {
    evaluateSize(5.5);

    for ($minute = 1; $minute <= 59; $minute++) {
        $this->travel(1)->minutes();
        evaluateSize($minute % 2 === 0 ? 7.9 : 8.1);
    }

    expect(count(sizeMessages()))->toBe(2)
        ->and(sizeState()->meta['level'])->toBe(1);
});

it('does not flap on a wide swing around critical, one run every 15 minutes for an hour', function () {
    evaluateSize(8.1);

    foreach ([7.5, 8.1, 7.5, 8.1] as $gb) {
        $this->travel(15)->minutes();
        evaluateSize($gb);
    }

    expect(count(sizeMessages()))->toBeLessThanOrEqual(3);
});

it('lets a partial measurement raise but never lower or clear a level', function () {
    evaluateSize(5.5);
    evaluateSize(1, partial: true);

    expect(sizeState()->problem_since)->not->toBeNull()
        ->and(sizeState()->meta['level'])->toBe(0)
        ->and(sizeMessages())->toHaveCount(1);

    evaluateSize(8.5, partial: true);
    expect(sizeState()->meta['level'])->toBe(1)->and(sizeMessages())->toHaveCount(2);

    evaluateSize(5.5, partial: true);
    expect(sizeState()->meta['level'])->toBe(1);

    evaluateSize(1, partial: false);
    expect(sizeState()->problem_since)->toBeNull();
});

it('keeps meta.level through a failed send and retries after 5 minutes', function () {
    sizeTelegramWorks(false);

    evaluateSize(6);
    expect(sizeState()->meta['level'])->toBe(0)
        ->and(sizeState()->meta)->toHaveKey('last_try')
        ->and(sizeState()->alerted_at)->toBeNull();

    $count = Http::recorded()->count();
    $this->travel(2)->minutes();
    evaluateSize(6);
    expect(Http::recorded()->count())->toBe($count);

    $this->travel(4)->minutes();
    sizeTelegramWorks(true);
    evaluateSize(6);
    expect(sizeState()->alerted_at)->not->toBeNull()->and(sizeState()->meta['level'])->toBe(0);
});

it('keeps meta.level when Telegram is not configured', function () {
    sizeTelegram(false);

    evaluateSize(9);

    expect(sizeState()->meta['level'])->toBe(1)->and(sizeState()->meta)->toHaveKey('skipped_at');
});

it('leaves track() meta unchanged for the existing problems', function () {
    (new ProblemMonitor)->track('problem:test_plain', true, 'queue_stuck', ['age' => 12, 'pending' => 3]);

    $state = MonitoringState::query()->find('problem:test_plain');

    expect($state->meta)->toBeNull()->and($state->alerted_at)->not->toBeNull();
});

it('puts formatted sizes and the limit in Telegram, never raw bytes, paths or the redaction mask', function () {
    evaluateSize(5.4);

    $text = sizeMessages()[0];

    expect($text)->toContain('5.4 GB')->toContain('5 GB')
        ->and($text)->not->toContain('[redacted]')->not->toContain('[number]')
        ->and($text)->not->toContain((string) (int) round(5.4 * GB))
        ->and($text)->not->toContain(storage_path())->not->toContain(base_path())
        ->and($text)->not->toMatch('/telegram\.monitoring/');
});

it('has the alert titles in uz, ru and en', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        foreach (['events.project_size_warn', 'events.project_size_critical', 'recovered.project_size', 'labels.project_size', 'labels.size_limit'] as $key) {
            expect(__("telegram.monitoring.{$key}", [], $locale))->not->toBe("telegram.monitoring.{$key}");
        }
    }

    expect(__('telegram.monitoring.events.project_size_warn', [], 'uz'))->not->toBe(__('telegram.monitoring.events.project_size_warn', [], 'en'));
});

it('evaluates the alert only after a done run, never after a failed one', function () {
    $this->scratch = realpath(sys_get_temp_dir()).'/poson-alert-'.bin2hex(random_bytes(6));
    mkdir($this->scratch.'/app/vendor', 0777, true);
    file_put_contents($this->scratch.'/app/vendor/v.php', 'a');
    config(['monitoring.disk' => ['warn_gb' => 5, 'critical_gb' => 8]]);
    Setting::query()->create(['key' => DiskThresholds::KEY, 'group' => 'monitoring', 'value' => ['warn_gb' => 1, 'critical_gb' => 2]]);
    CompanySettings::flush();

    // A tiny tree is below every limit: done, no alert.
    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/app', $this->scratch.'/app/storage'));
    app()->call([new MeasureProjectSize(app(StartDiskMeasurement::class)->claim()), 'handle']);
    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Done)->and(sizeMessages())->toBe([]);

    // Open a problem, then a failed run must leave it untouched.
    evaluateSize(1.5);
    $messages = count(sizeMessages());
    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/nope', $this->scratch.'/nope/storage'));
    $this->travel(20)->minutes();
    app()->call([new MeasureProjectSize(app(StartDiskMeasurement::class)->claim()), 'handle']);

    expect(DiskRun::current()->status)->toBe(DiskRunStatus::Failed)
        ->and(sizeState()->problem_since)->not->toBeNull()
        ->and(count(sizeMessages()))->toBe($messages);

    // The next done run below the limit sends "recovered".
    app()->bind(FolderSizer::class, fn () => new FolderSizer($this->scratch.'/app', $this->scratch.'/app/storage'));
    $this->travel(20)->minutes();
    app()->call([new MeasureProjectSize(app(StartDiskMeasurement::class)->claim()), 'handle']);

    expect(sizeState()->problem_since)->toBeNull()
        ->and(last(sizeMessages()))->toContain(__('telegram.monitoring.recovered.project_size', [], 'uz'));

    File::deleteDirectory($this->scratch);
});

// Thresholds -----------------------------------------------------------------

it('defaults to 5 and 8 GB', function () {
    expect(app(DiskThresholds::class)->gb())->toBe(['warn_gb' => 5, 'critical_gb' => 8])
        ->and(app(DiskThresholds::class)->warnBytes())->toBe(5 * GB)
        ->and(app(DiskThresholds::class)->criticalBytes())->toBe(8 * GB);
});

it('falls back to both defaults for any corrupted stored value and still alerts at 6 GB', function (mixed $value) {
    Setting::query()->create(['key' => DiskThresholds::KEY, 'group' => 'monitoring', 'value' => $value]);
    CompanySettings::flush();

    expect(app(DiskThresholds::class)->gb())->toBe(['warn_gb' => 5, 'critical_gb' => 8]);

    evaluateSize(6);
    expect(sizeMessages())->toHaveCount(1);
})->with([
    'strings' => [['warn_gb' => '20', 'critical_gb' => '30']],
    'critical below warn' => [['warn_gb' => 20, 'critical_gb' => 10]],
    'critical equals warn' => [['warn_gb' => 20, 'critical_gb' => 20]],
    'zero' => [['warn_gb' => 0, 'critical_gb' => 8]],
    'huge' => [['warn_gb' => 99999, 'critical_gb' => 100000]],
    'float' => [['warn_gb' => 5.5, 'critical_gb' => 9]],
    'not an array' => ['nonsense'],
    'one field only' => [['warn_gb' => 50]],
]);

it('saves valid limits with manage, a recent password and an audit row, and the next evaluation uses them', function () {
    $admin = staffUser(Role::Admin);
    confirmAdminPassword();

    app(UpdateDiskThresholds::class)->handle($admin, 20, 30, '203.0.113.9');

    $audit = SettingsAudit::query()->where('key', DiskThresholds::KEY)->firstOrFail();

    expect(app(DiskThresholds::class)->gb())->toBe(['warn_gb' => 20, 'critical_gb' => 30])
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->action)->toBe(AuditAction::Updated)
        ->and($audit->old_text)->toBe('5/8 GB')
        ->and($audit->new_text)->toBe('20/30 GB');

    evaluateSize(6);
    expect(sizeMessages())->toBe([]);

    evaluateSize(21);
    expect(sizeMessages())->toHaveCount(1)->and(sizeMessages()[0])->toContain('20 GB');
});

it('rejects invalid limits', function (mixed $warn, mixed $critical) {
    $admin = staffUser(Role::Admin);
    confirmAdminPassword();

    expect(fn () => app(UpdateDiskThresholds::class)->handle($admin, $warn, $critical))->toThrow(MonitoringActionException::class);
    expect(Setting::query()->where('key', DiskThresholds::KEY)->exists())->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'zero warn' => [0, 5],
    'negative' => [-3, 5],
    'string' => ['5', '8'],
    'float' => [5.5, 9],
    'critical equals warn' => [5, 5],
    'critical below warn' => [9, 4],
    'warn too big' => [10000, 10001],
    'critical too big' => [5, 10001],
    'null' => [null, null],
]);

it('needs the password confirmation, the manage permission and a rate limit to save', function () {
    $admin = staffUser(Role::Admin);

    expect(fn () => app(UpdateDiskThresholds::class)->handle($admin, 20, 30))->toThrow(MonitoringActionException::class, 'password');

    confirmAdminPassword();
    expect(fn () => app(UpdateDiskThresholds::class)->handle(null, 20, 30))->toThrow(AuthorizationException::class);

    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        expect(fn () => app(UpdateDiskThresholds::class)->handle(staffUser($role), 20, 30))->toThrow(AuthorizationException::class);
    }

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('monitoring:disk-limits:'.$admin->id, 600);
    }

    expect(fn () => app(UpdateDiskThresholds::class)->handle($admin, 20, 30))->toThrow(MonitoringActionException::class, 'rate');
    expect(Setting::query()->where('key', DiskThresholds::KEY)->exists())->toBeFalse();
});
