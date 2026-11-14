<?php

use App\Enums\ActivityType;
use App\Enums\JobApplicationCvStatus;
use App\Jobs\ForwardJobApplicationCv;
use App\Jobs\NotifyJobApplication;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Models\Vacancy;
use App\Services\Telegram\TelegramClient;
use App\Support\Careers\CareersCaps;
use App\Support\Careers\CareersSwitch;
use App\Support\Careers\CvFile;
use App\Support\Careers\PendingCv;
use App\Support\Phone;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Helpers/Careers.php';

beforeEach(function () {
    Storage::fake('local');
});

function hardeningPending(): JobApplication
{
    return JobApplication::factory()->forVacancy(Vacancy::factory()->published()->create())->create([
        'cv_status' => JobApplicationCvStatus::Pending,
        'cv_pending_path' => PendingCv::write(careersPdf()),
        'cv_original_name' => 'cv.pdf',
        'cv_mime' => 'application/pdf',
        'cv_size' => 120,
    ]);
}

it('deletes the sent message at once when the application was erased during the send (CV)', function () {
    $application = hardeningPending();
    careersTelegram(responses: [
        'sendDocument' => function () use ($application) {
            JobApplication::query()->whereKey($application->id)->delete();

            return Http::response(['ok' => true, 'result' => ['message_id' => 811, 'document' => ['file_id' => 'F', 'file_unique_id' => 'U']]]);
        },
    ]);

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    expect(careersTelegramMethods())->toContain('deleteMessage')
        ->and(collect(telegramApiCalls())->where('method', 'deleteMessage')->count())->toBe(1)
        ->and(collect(telegramApiCalls())->firstWhere('method', 'deleteMessage')['data']['message_id'])->toBe(811)
        ->and(Activity::query()->where('type', ActivityType::Notification)->count())->toBe(0);
});

it('deletes the sent message when the application was erased during the text alert', function () {
    $application = JobApplication::factory()->forVacancy(Vacancy::factory()->published()->create())->create();
    careersTelegram(responses: [
        'sendMessage' => function () use ($application) {
            JobApplication::query()->whereKey($application->id)->delete();

            return Http::response(['ok' => true, 'result' => ['message_id' => 812]]);
        },
    ]);

    (new NotifyJobApplication($application->id))->handle(app(TelegramClient::class));

    expect(collect(telegramApiCalls())->where('method', 'deleteMessage')->count())->toBe(1);
});

it('keeps the forwarded state when only the activity row fails (no resend)', function () {
    careersTelegram();
    $application = hardeningPending();
    Activity::creating(fn () => throw new RuntimeException('db'));

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    expect($application->fresh()->cv_status)->toBe(JobApplicationCvStatus::Forwarded)
        ->and(collect(telegramApiCalls())->where('method', 'sendDocument')->count())->toBe(1);
});

it('releases the job instead of failing when the target is refused', function () {
    careersTelegram(['id' => -1008888888888, 'type' => 'private']);
    $application = hardeningPending();

    $job = Mockery::mock(ForwardJobApplicationCv::class.'[release]', [$application->id]);
    $job->shouldReceive('release')->once()->with(600);
    $job->handle(app(TelegramClient::class));

    expect($application->fresh()->cv_status)->toBe(JobApplicationCvStatus::Pending);
});

it('names Telegram as processor even when the CV field is not offered', function () {
    $vacancy = Vacancy::factory()->published()->create();

    $this->get($vacancy->url('en'))->assertOk()->assertDontSee('data-careers-cv', false)->assertSee('Telegram', false);
});

it('saves the 2nd application over the hourly cap without a per-application message, one cap alert', function () {
    Queue::fake();
    careersTelegram();
    config(['careers.caps.applications_per_hour' => 1]);
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy)->assertSessionHas('careers_sent');
    postVacancyApplication($vacancy, ['phone' => '+998 90 555 55 55'])->assertSessionHas('careers_sent');
    postVacancyApplication($vacancy, ['phone' => '+998 90 666 66 66'])->assertSessionHas('careers_sent');

    expect(JobApplication::query()->count())->toBe(3)
        ->and(collect(telegramApiCalls())->where('method', 'sendMessage')->count())->toBe(1);
    Queue::assertPushed(NotifyJobApplication::class, 1);
});

it('counts a CV against the hourly cap atomically and refunds a refused one', function () {
    RateLimiter::clear(CareersCaps::CV_KEY);
    config(['careers.caps.cv_forwards_per_hour' => 1]);

    expect(CareersCaps::cvAllowed())->toBeTrue()
        ->and(CareersCaps::cvAllowed())->toBeFalse()
        ->and(RateLimiter::attempts(CareersCaps::CV_KEY))->toBe(1);

    RateLimiter::clear(CareersCaps::CV_KEY);
    config(['careers.caps.cv_forwards_per_hour' => 20, 'careers.caps.pending_files' => 0]);

    expect(CareersCaps::cvAllowed())->toBeFalse()->and(RateLimiter::attempts(CareersCaps::CV_KEY))->toBe(0);
});

it('rejects PDF name tokens that hide active content with #xx escapes', function (string $body) {
    expect(CvFile::looksLikeSafePdf(careersPdf($body)))->toBeFalse();
})->with([
    'JavaScript' => '1 0 obj << /J#61vaScript (x) >> endobj',
    'JS' => '1 0 obj << /#4A#53 (x) >> endobj',
    'Launch' => '1 0 obj << /La#75nch >> endobj',
]);

it('accepts a PDF with object streams', function () {
    expect(CvFile::looksLikeSafePdf(careersPdf('5 0 obj << /Type /ObjStm /N 3 /First 12 /Length 40 >> stream endstream endobj')))->toBeTrue();
});

it('keys the phone limiter by an HMAC, not a bare hash', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();
    $phone = Phone::normalizeAny('+998 90 123 45 67');

    postVacancyApplication($vacancy);

    expect(RateLimiter::attempts('careers-phone:'.hash_hmac('sha256', (string) $phone, config('app.key'))))->toBe(1)
        ->and(RateLimiter::attempts('careers-phone:'.sha1((string) $phone)))->toBe(0);
});

it('re-reads the kill switches after the scoped memo is dropped', function () {
    expect(CareersSwitch::applicationsEnabled())->toBeTrue();

    Setting::query()->create(['key' => 'careers.applications_enabled', 'group' => 'careers', 'value' => false]);
    expect(CareersSwitch::applicationsEnabled())->toBeTrue();

    app()->forgetScopedInstances();
    expect(CareersSwitch::applicationsEnabled())->toBeFalse();
});

it('queues waiting pending CVs again from the expire command', function () {
    Queue::fake();
    $application = hardeningPending();
    JobApplication::query()->whereKey($application->id)->update(['updated_at' => now()->subMinutes(75)]);

    $this->artisan('careers:expire-pending-cvs')->assertSuccessful();

    Queue::assertPushed(ForwardJobApplicationCv::class, fn ($job) => $job->jobApplicationId === $application->id);
});

it('never queues the same waiting CV twice and only after 70 minutes', function () {
    Queue::fake();
    $application = hardeningPending();

    JobApplication::query()->whereKey($application->id)->update(['updated_at' => now()->subMinutes(60)]);
    $this->artisan('careers:expire-pending-cvs')->assertSuccessful();
    Queue::assertNothingPushed();

    JobApplication::query()->whereKey($application->id)->update(['updated_at' => now()->subMinutes(71)]);
    $this->artisan('careers:expire-pending-cvs')->assertSuccessful();
    $this->artisan('careers:expire-pending-cvs')->assertSuccessful();

    Queue::assertPushed(ForwardJobApplicationCv::class, 1);
});

it('keeps the pending file when both post-send updates fail and never overwrites expired', function () {
    careersTelegram();
    $application = hardeningPending();
    $path = $application->cv_pending_path;
    $listener = DB::listen(function ($query): void {
        if (str_contains($query->sql, 'telegram_message_id')) {
            throw new RuntimeException('db');
        }
    });

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));
    expect(Storage::disk('local')->exists($path))->toBeTrue();
    DB::getEventDispatcher()->forget(QueryExecuted::class);

    $expired = hardeningPending();
    JobApplication::query()->whereKey($expired->id)->update(['cv_status' => JobApplicationCvStatus::Expired]);
    (new ForwardJobApplicationCv($expired->id))->handle(app(TelegramClient::class));
    expect($expired->fresh()->cv_status)->toBe(JobApplicationCvStatus::Expired);
});

it('rejects a PDF when the marker regex errors (fail closed)', function () {
    $previous = [ini_get('pcre.jit'), ini_get('pcre.backtrack_limit')];
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect(CvFile::looksLikeSafePdf(careersPdf('/'.str_repeat('a#41', 3000).' /Info')))->toBeFalse();
    } finally {
        ini_set('pcre.jit', $previous[0]);
        ini_set('pcre.backtrack_limit', $previous[1]);
    }
});

it('rejects --draft together with --publish', function () {
    $this->artisan('careers:install-samples --draft --publish')->assertFailed();

    expect(Vacancy::query()->count())->toBe(0);
});
