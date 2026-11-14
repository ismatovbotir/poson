<?php

use App\Actions\Careers\DeleteVacancy;
use App\Enums\JobApplicationCvStatus;
use App\Enums\VacancyDepartment;
use App\Jobs\ForwardJobApplicationCv;
use App\Jobs\NotifyJobApplication;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Models\Vacancy;
use App\Support\Careers\CareersCaps;
use App\Support\Careers\CareersSwitch;
use App\Support\Careers\CvFile;
use App\Support\Careers\FormToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Helpers/Careers.php';

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
});

it('saves a valid application to a vacancy, copies its department and queues the text alert', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create(['department' => VacancyDepartment::Software]);

    postVacancyApplication($vacancy, ['name' => "  <b>Ali</b>\u{200B} Valiyev ", 'message' => "Salom\u{0007}\n\n\n\nDunyo"])
        ->assertRedirect($vacancy->url('uz').'#careers-apply')
        ->assertSessionHas('careers_sent', true);

    $application = JobApplication::query()->sole();

    expect($application->vacancy_id)->toBe($vacancy->id)
        ->and($application->department)->toBe(VacancyDepartment::Software)
        ->and($application->name)->toBe('Ali Valiyev')
        ->and($application->phone)->toBe('+998901234567')
        ->and($application->email)->toBe('ali@example.uz')
        ->and($application->message)->toBe("Salom\n\nDunyo")
        ->and($application->locale)->toBe('uz')
        ->and($application->cv_status)->toBe(JobApplicationCvStatus::None);

    Queue::assertPushed(NotifyJobApplication::class, fn (NotifyJobApplication $job): bool => $job->jobApplicationId === $application->id);
    Queue::assertNotPushed(ForwardJobApplicationCv::class);
});

it('accepts an open application to a department end to end, with the CV forwarded', function () {
    careersTelegram();

    postOpenApplication('sales', [], careersUpload())
        ->assertRedirect(route('site.careers.open', ['locale' => 'uz', 'department' => 'sales']).'#careers-apply')
        ->assertSessionHas('careers_sent', true);

    $application = JobApplication::query()->sole();
    $documents = careersSentDocuments();

    expect($application->vacancy_id)->toBeNull()
        ->and($application->department)->toBe(VacancyDepartment::Sales)
        ->and($application->cv_status)->toBe(JobApplicationCvStatus::Forwarded)
        ->and($application->cv_pending_path)->toBeNull()
        ->and($documents)->toHaveCount(1)
        ->and($documents[0]['caption'])->toContain('#J-'.$application->id)
        ->and($documents[0]['caption'])->toContain(__('careers.telegram.open_application', ['department' => VacancyDepartment::Sales->label()], 'uz'))
        ->and(Storage::disk('local')->files(CareersCaps::PENDING_DIR))->toBe([]);
});

it('answers 404 for an unpublished vacancy and stores nothing for an unknown department', function () {
    $draft = Vacancy::factory()->create();

    // An unknown department can't match any token: the bot answer (same success), nothing stored.
    $this->post('/uz/careers/apply/nosuch', careersFields(['department' => 'nosuch']))->assertSessionHas('careers_sent');
    postVacancyApplication($draft)->assertNotFound();

    expect(JobApplication::query()->count())->toBe(0);
});

it('treats honeypot, missing, tampered, replayed, other-target, too fast and expired tokens as bots: same success, nothing stored or inspected', function (Closure $overrides) {
    Queue::fake();
    careersTelegram();
    $vacancy = Vacancy::factory()->published()->create();
    $other = Vacancy::factory()->published()->create();
    $inspected = false;
    $this->app->bind(CvFile::class, function () use (&$inspected) {
        $inspected = true;
    });

    postVacancyApplication($vacancy, $overrides($vacancy, $other), careersUpload())
        ->assertRedirect($vacancy->url('uz').'#careers-apply')
        ->assertSessionHas('careers_sent', true)
        ->assertSessionDoesntHaveErrors();

    expect(JobApplication::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(RateLimiter::attempts('careers-ip:'.sha1('127.0.0.1')))->toBe(0)
        ->and($inspected)->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'honeypot' => fn ($vacancy) => ['fax_ext' => 'bot'],
    'honeypot array' => fn ($vacancy) => ['fax_ext' => ['x']],
    'no token' => fn ($vacancy) => ['form_token' => ''],
    'tampered' => fn ($vacancy) => ['form_token' => substr(careersToken(['vacancy_id' => $vacancy->id]), 0, -4).'AAAA'],
    'plain encrypt()' => fn ($vacancy) => ['form_token' => encrypt(['t' => now()->getTimestamp() - 5, 'vacancy_id' => $vacancy->id])],
    'other vacancy' => fn ($vacancy, $other) => ['form_token' => careersToken(['vacancy_id' => $other->id])],
    'department token' => fn ($vacancy) => ['form_token' => careersToken(['department' => 'sales'])],
    'too fast' => fn ($vacancy) => ['form_token' => careersToken(['vacancy_id' => $vacancy->id], age: 0)],
    'expired' => fn ($vacancy) => ['form_token' => careersToken(['vacancy_id' => $vacancy->id], age: FormToken::MAX_SECONDS + 5)],
]);

it('accepts a form token only once', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();
    $token = careersToken(['vacancy_id' => $vacancy->id]);

    postVacancyApplication($vacancy, ['form_token' => $token])->assertSessionHas('careers_sent');
    postVacancyApplication($vacancy, ['form_token' => $token, 'phone' => '+998 91 000 00 01'])->assertSessionHas('careers_sent');

    expect(JobApplication::query()->count())->toBe(1);
});

it('binds the open application token to its department', function () {
    Queue::fake();

    postOpenApplication('sales', ['form_token' => careersToken(['department' => 'service'])])->assertSessionHas('careers_sent');

    expect(JobApplication::query()->count())->toBe(0);
});

it('rejects invalid fields with errors in the careers bag, never flashing the file or the token', function () {
    $vacancy = Vacancy::factory()->published()->create();

    $response = postVacancyApplication($vacancy, ['name' => '', 'phone' => '123', 'email' => 'not-an-email'])
        ->assertRedirect($vacancy->url('uz').'#careers-apply')
        ->assertSessionHasErrors(['name', 'phone', 'email'], errorBag: 'careers');

    expect(session()->getOldInput())->not->toHaveKeys(['cv', 'form_token', 'fax_ext'])
        ->and(session()->getOldInput('email'))->toBe('not-an-email')
        ->and(JobApplication::query()->count())->toBe(0);
});

it('rejects a CV that is not a safe PDF or is too large, with no file stored', function (Closure $upload) {
    careersTelegram();
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy, [], $upload())
        ->assertRedirect($vacancy->url('uz').'#careers-apply')
        ->assertSessionHasErrors(errorBag: 'careers');

    expect(JobApplication::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(careersTelegramMethods())->toBe([]);
})->with([
    'exe renamed to pdf' => fn () => careersUpload("MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 200), 'cv.pdf'),
    'pdf with JavaScript' => fn () => careersUpload(careersPdf('1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj')),
    'pdf with Launch' => fn () => careersUpload(careersPdf('1 0 obj << /OpenAction << /S /Launch /F (cmd.exe) >> >> endobj')),
    'pdf with embedded file' => fn () => careersUpload(careersPdf('1 0 obj << /Type /EmbeddedFile >> endobj')),
    'wrong extension' => fn () => careersUpload(careersPdf(), 'cv.docx'),
    'over 5 MB' => fn () => UploadedFile::fake()->create('cv.pdf', 5121, 'application/pdf'),
]);

it('shows a friendly "file too large" when PHP drops an oversized body', function () {
    $vacancy = Vacancy::factory()->published()->create();
    $response = $this->call('POST', "/uz/careers/{$vacancy->slug_uz}/apply", [], [], [], ['CONTENT_LENGTH' => 9 * 1024 * 1024 * 1024]);

    $response->assertRedirect($vacancy->url('uz').'?upload=too_large#careers-apply');
    $this->get($vacancy->url('uz').'?upload=too_large')->assertOk()->assertSee(__('careers.site.form.cv_too_large', [], 'uz'));
});

it('limits applications per IP and per phone with own keys, and foreign numbers by the global cap', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();

    foreach (['+998 90 000 00 01', '+998 90 000 00 02', '+998 90 000 00 03'] as $phone) {
        postVacancyApplication($vacancy, ['phone' => $phone])->assertSessionHas('careers_sent');
    }

    postVacancyApplication($vacancy, ['phone' => '+998 90 000 00 04'])->assertSessionHasErrors(['form'], errorBag: 'careers');
    expect(JobApplication::query()->count())->toBe(3)
        ->and(RateLimiter::attempts('site-lead:'.sha1('127.0.0.1')))->toBe(0);

    // Past the route throttle window (5 / 10 min), still inside the hourly limits.
    $this->travel(11)->minutes();
    RateLimiter::clear('careers-ip:'.sha1('127.0.0.1'));
    postVacancyApplication($vacancy, ['phone' => '+998 90 000 00 01'])->assertSessionHas('careers_sent');
    postVacancyApplication($vacancy, ['phone' => '+998 90 000 00 01'])->assertSessionHasErrors(['form'], errorBag: 'careers');
    expect(JobApplication::query()->where('phone', '+998900000001')->count())->toBe(2);

    $this->travel(11)->minutes();
    RateLimiter::clear('careers-ip:'.sha1('127.0.0.1'));
    config(['site.foreign_phone_limit_per_hour' => 0]);
    postVacancyApplication($vacancy, ['phone' => '+49 30 1234 5678'])->assertSessionHasErrors(['form'], errorBag: 'careers');
    expect(JobApplication::query()->where('phone', 'like', '+49%')->count())->toBe(0);
});

it('throttles the route per IP before the bot check', function () {
    $vacancy = Vacancy::factory()->published()->create();

    foreach (range(1, 5) as $attempt) {
        postVacancyApplication($vacancy, ['fax_ext' => 'bot'])->assertRedirect();
    }

    postVacancyApplication($vacancy, ['fax_ext' => 'bot'])->assertStatus(429);
});

it('hides the form and refuses the POST while the kill switch is off', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();
    Setting::query()->create(['key' => 'careers.applications_enabled', 'group' => 'careers', 'value' => false]);

    $this->get($vacancy->url('uz'))->assertOk()->assertSee(__('careers.site.form.closed', [], 'uz'))->assertDontSee('data-careers-form', false);
    $this->get('/uz/careers/apply/sales')->assertOk()->assertDontSee('data-careers-form', false);

    postVacancyApplication($vacancy)->assertSessionHasErrors(['form'], errorBag: 'careers');
    postOpenApplication('sales')->assertSessionHasErrors(['form'], errorBag: 'careers');

    expect(JobApplication::query()->count())->toBe(0);
});

it('shows the CV field only while Telegram is configured and CVs are switched on', function () {
    $vacancy = Vacancy::factory()->published()->create();

    $this->get($vacancy->url('uz'))->assertOk()->assertSee('data-careers-form', false)->assertDontSee('data-careers-cv', false);

    careersTelegram();
    CareersSwitch::forget();
    $this->get($vacancy->url('uz'))->assertSee('data-careers-cv', false)->assertSee('PDF', false)
        ->assertSee(__('careers.site.form.privacy_cv', [], 'uz'));

    Setting::query()->create(['key' => 'careers.cv_enabled', 'group' => 'careers', 'value' => false]);
    CareersSwitch::forget();
    $this->get($vacancy->url('uz'))->assertSee('data-careers-form', false)->assertDontSee('data-careers-cv', false);
});

it('ignores a sent CV while Telegram is not configured', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy, [], careersUpload())->assertSessionHas('careers_sent');

    expect(JobApplication::query()->sole()->cv_status)->toBe(JobApplicationCvStatus::None)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps the CV as an encrypted pending file without any PDF bytes on disk', function () {
    Queue::fake();
    careersTelegram();
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy, [], careersUpload(null, "..\\evil/\u{202E}fdp.exe<>|.pdf"))->assertSessionHas('careers_sent');

    $application = JobApplication::query()->sole();
    $files = Storage::disk('local')->allFiles();

    expect($application->cv_status)->toBe(JobApplicationCvStatus::Pending)
        ->and($application->cv_pending_path)->toMatch('#^careers/pending/[0-9A-Z]{26}\.bin$#')
        ->and($application->cv_original_name)->toBe('fdp.exe.pdf')
        ->and($application->cv_mime)->toBe('application/pdf')
        ->and($files)->toBe([$application->cv_pending_path])
        ->and(Storage::disk('local')->get($files[0]))->not->toContain('%PDF');

    Queue::assertPushed(ForwardJobApplicationCv::class, function (ForwardJobApplicationCv $job) use ($application): bool {
        $payload = serialize($job);

        return $job->jobApplicationId === $application->id && ! str_contains($payload, 'Ali') && ! str_contains($payload, '998');
    });
});

it('drops the CV over a site-wide cap, saves text-only and alerts once with counts only', function (string $cap) {
    Queue::fake();
    careersTelegram();
    $vacancy = Vacancy::factory()->published()->create();
    config(["careers.caps.{$cap}" => 0]);

    postVacancyApplication($vacancy, [], careersUpload())->assertSessionHas('careers_sent');
    postVacancyApplication($vacancy, ['phone' => '+998 90 555 55 55'], careersUpload())->assertSessionHas('careers_sent');

    expect(JobApplication::query()->pluck('cv_status')->unique()->all())->toBe([JobApplicationCvStatus::None])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(careersTelegramMethods())->not->toContain('sendDocument');

    $alerts = collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text');
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toContain(__('telegram.monitoring.events.careers_cv', [], 'uz'))
        ->and($alerts[0])->not->toContain('Ali')
        ->and($alerts[0])->not->toContain('998');
    Queue::assertNotPushed(ForwardJobApplicationCv::class);
})->with(['applications_per_hour', 'cv_forwards_per_hour', 'pending_files']);

it('leaves no file behind when the transaction rolls back', function () {
    careersTelegram();
    $vacancy = Vacancy::factory()->published()->create();
    JobApplication::creating(fn () => throw new RuntimeException('db down'));

    postVacancyApplication($vacancy, [], careersUpload())->assertSessionHasErrors(['form'], errorBag: 'careers');

    expect(JobApplication::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('logs only ids and codes, never the candidate data', function () {
    Queue::fake();
    Log::spy();
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy, ['email' => 'secret@example.uz']);
    postVacancyApplication($vacancy, ['email' => 'secret@example.uz', 'name' => '']);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
        $dump = $message.json_encode($context);

        expect($dump)->not->toContain('Ali')->not->toContain('998')->not->toContain('secret@');

        return true;
    });

    foreach (['warning', 'error', 'debug', 'notice'] as $level) {
        Log::shouldNotHaveReceived($level, [Mockery::on(fn ($m) => str_contains((string) $m, 'Ali'))]);
    }
});

it('locks the vacancy so it cannot be deleted while an application is being stored', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy)->assertSessionHas('careers_sent');

    expect(fn () => app(DeleteVacancy::class)->handle($vacancy))->toThrow(ValidationException::class)
        ->and(Vacancy::query()->whereKey($vacancy->id)->exists())->toBeTrue();
});
