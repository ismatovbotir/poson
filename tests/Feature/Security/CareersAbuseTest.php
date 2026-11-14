<?php

use App\Actions\Careers\DeleteJobApplication;
use App\Enums\ActivityType;
use App\Enums\JobApplicationCvStatus;
use App\Enums\JobApplicationStatus;
use App\Enums\Role;
use App\Jobs\ForwardJobApplicationCv;
use App\Jobs\NotifyJobApplication;
use App\Livewire\Admin\Careers\Applications\Show;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Models\Vacancy;
use App\Services\Telegram\TelegramClient;
use App\Support\Careers\CareersCaps;
use App\Support\Careers\CareersSwitch;
use App\Support\Careers\JobApplicationMessage;
use App\Support\Careers\PendingCv;
use App\Support\Site\CanonicalHost;
use App\Support\Telegram\PrivateGroupTarget;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/Careers.php';

/*
| Plan 31 section 20: the 18 security abuse cases of the careers module
| (public form, CV forward, admin download). In-process only: stray HTTP is
| refused and the Bot API is faked.
*/

beforeEach(function () {
    Storage::fake('local');
    careersTelegram();
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

if (! function_exists('abuseForwardedCv')) {
    /**
     * An application whose CV sits in the management chat.
     *
     * @param  array<string, mixed>  $attributes
     */
    function abuseForwardedCv(array $attributes = []): JobApplication
    {
        return JobApplication::factory()->create(array_replace([
            'cv_status' => JobApplicationCvStatus::Forwarded,
            'cv_file_id' => 'BQACAgIAAxkBAAIFAKE',
            'cv_file_unique_id' => 'AgADFAKE',
            'cv_original_name' => 'Ali Valiyev CV.pdf',
            'cv_mime' => 'application/pdf',
            'cv_size' => 120,
            'cv_forwarded_at' => now(),
            'telegram_message_id' => 701,
        ], $attributes));
    }

    function abusePendingCv(): JobApplication
    {
        return JobApplication::factory()->forVacancy(Vacancy::factory()->published()->create())->create([
            'name' => 'Ali Valiyev',
            'cv_status' => JobApplicationCvStatus::Pending,
            'cv_pending_path' => PendingCv::write(careersPdf()),
            'cv_original_name' => 'cv.pdf',
            'cv_mime' => 'application/pdf',
            'cv_size' => 120,
        ]);
    }

    /**
     * @return list<string>
     */
    function abusePendingFiles(): array
    {
        return Storage::disk('local')->allFiles(CareersCaps::PENDING_DIR);
    }

    function abuseDownload(JobApplication $application): TestResponse
    {
        config(['app.debug' => false]);

        return test()->actingAs(staffUser())->get(route('admin.careers.applications.cv', $application->id));
    }

    /**
     * @return list<string>
     */
    function abuseRequestedUrls(): array
    {
        return Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->values()->all();
    }

    function abuseFileRequests(): int
    {
        return count(array_filter(abuseRequestedUrls(), fn (string $url): bool => str_contains($url, '/file/bot')));
    }

    function abuseAssertNothingStored(): void
    {
        expect(JobApplication::query()->count())->toBe(0)
            ->and(Activity::query()->count())->toBe(0)
            ->and(abusePendingFiles())->toBe([])
            ->and(careersTelegramMethods())->toBe([]);
    }
}

// Abuse case 1: a token minted for vacancy A (or a department) posted to another target is a bot.
it('treats a token minted for another target as a bot: same success, nothing stored, counted or inspected', function (Closure $post) {
    Queue::fake();
    $a = Vacancy::factory()->published()->create();
    $b = Vacancy::factory()->published()->create();

    $post($a, $b)->assertSessionHas('careers_sent', true)->assertSessionDoesntHaveErrors();

    abuseAssertNothingStored();
    expect(RateLimiter::attempts('careers-ip:'.sha1('127.0.0.1')))->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'vacancy A token to vacancy B' => fn ($a, $b) => postVacancyApplication($b, ['form_token' => careersToken(['vacancy_id' => $a->id])], careersUpload()),
    'vacancy token to /careers/apply/sales' => fn ($a, $b) => postOpenApplication('sales', ['form_token' => careersToken(['vacancy_id' => $a->id])], careersUpload()),
    'sales token to /careers/apply/service' => fn ($a, $b) => postOpenApplication('service', ['form_token' => careersToken(['department' => 'sales'])], careersUpload()),
    'department token to a vacancy' => fn ($a, $b) => postVacancyApplication($a, ['form_token' => careersToken(['department' => 'sales'])], careersUpload()),
]);

// Abuse case 2: replaying one token stores one row.
it('stores a single application when the same token is posted twice', function (string $target) {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();
    $send = fn (string $token, string $phone) => $target === 'vacancy'
        ? postVacancyApplication($vacancy, ['form_token' => $token, 'phone' => $phone])
        : postOpenApplication('sales', ['form_token' => $token, 'phone' => $phone]);
    $token = $target === 'vacancy' ? careersToken(['vacancy_id' => $vacancy->id]) : careersToken(['department' => 'sales']);

    $send($token, '+998 90 100 00 01')->assertSessionHas('careers_sent');
    $send($token, '+998 90 100 00 02')->assertSessionHas('careers_sent')->assertSessionDoesntHaveErrors();

    expect(JobApplication::query()->count())->toBe(1)
        ->and(JobApplication::query()->where('phone', '+998901000002')->exists())->toBeFalse();
    Queue::assertPushed(NotifyJobApplication::class, 1);
})->with(['vacancy', 'open application']);

// Abuse case 3: a token naming both a vacancy and a department is a bot.
it('treats a token carrying both vacancy_id and department as a bot', function (string $target) {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();
    $token = Crypt::encryptString((string) json_encode([
        't' => now()->getTimestamp() - 5, 'vacancy_id' => $vacancy->id, 'department' => 'sales', 'nonce' => Str::random(32),
    ]));

    $target === 'vacancy'
        ? postVacancyApplication($vacancy, ['form_token' => $token], careersUpload())->assertSessionHas('careers_sent')
        : postOpenApplication('sales', ['form_token' => $token], careersUpload())->assertSessionHas('careers_sent');

    abuseAssertNothingStored();
    Queue::assertNothingPushed();
})->with(['vacancy', 'open application']);

// Abuse case 4: wrong-typed bot fields never reach a 500.
it('answers the normal success, not a 500, to non-string tokens and array honeypots', function (array $overrides) {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy, $overrides)
        ->assertRedirect($vacancy->url('uz').'#careers-apply')
        ->assertSessionHas('careers_sent', true)
        ->assertSessionDoesntHaveErrors();

    abuseAssertNothingStored();
    Queue::assertNothingPushed();
})->with([
    'array token' => [['form_token' => ['a', 'b']]],
    'nested array token' => [['form_token' => [['x' => 1]]]],
    'array honeypot' => [['fax_ext' => ['x']]],
    'nested array honeypot' => [['fax_ext' => [['x' => ['y']]]]],
    'token 5000 chars' => [['form_token' => str_repeat('A', 5000)]],
    'token not base64' => [['form_token' => '{"t":1,"vacancy_id":1}']],
]);

// Abuse case 5: a CV that hides active content or is not a PDF is refused, nothing is stored or sent.
it('refuses PDFs with #xx-escaped active content and non-PDF bytes', function (string $bytes) {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy, [], careersUpload($bytes))
        ->assertRedirect($vacancy->url('uz').'#careers-apply')
        ->assertSessionHasErrors(errorBag: 'careers');

    abuseAssertNothingStored();
    Queue::assertNothingPushed();
})->with([
    '/J#61vaScript' => fn () => careersPdf('1 0 obj << /OpenAction << /S /J#61vaScript /JS (x) >> >> endobj'),
    '/#4A#53' => fn () => careersPdf('1 0 obj << /A << /#4A#53 (app.alert(1)) >> >> endobj'),
    '/La#75nch' => fn () => careersPdf('1 0 obj << /A << /S /La#75nch /F (cmd.exe) >> >> endobj'),
    '/Embedded#46ile' => fn () => careersPdf('1 0 obj << /Type /Embedded#46ile >> endobj'),
    'MZ executable with %PDF- later in the file' => fn () => "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 100)."\n%PDF-1.4\n%%EOF\n",
]);

// Abuse case 5 (known gap): a %PDF- header followed by an MZ executable body is accepted by CvFile::looksLikeSafePdf.
it('refuses a %PDF- header followed by an MZ executable body', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();
    $bytes = "%PDF-1.4\nMZ\x90\x00\x03\x00\x00\x00\x04\x00".str_repeat("\x00", 200)."This program cannot be run in DOS mode.\r\n";

    postVacancyApplication($vacancy, [], careersUpload($bytes))->assertSessionHasErrors(errorBag: 'careers');

    abuseAssertNothingStored();
    Queue::assertNothingPushed();
});

// Abuse case 6: a malicious file_path from getFile is never fetched.
it('refuses an unsafe getFile path with a generic 404 and no file request', function (string $path) {
    careersTelegram(responses: ['getFile' => Http::response(['ok' => true, 'result' => ['file_unique_id' => 'AgADFAKE', 'file_path' => $path]])]);
    $application = abuseForwardedCv();

    $response = abuseDownload($application)->assertNotFound();

    expect(abuseFileRequests())->toBe(0)
        ->and(careersTelegramMethods())->toBe(['getFile'])
        ->and(implode(' ', abuseRequestedUrls()))->not->toContain('evil')->not->toContain('getMe')
        ->and($response->getContent())->not->toContain(TG_BOT_TOKEN)->not->toContain('evil')
        ->and($application->activities()->where('type', ActivityType::CvDownload->value)->count())->toBe(0);
})->with(['../../bot123/getMe', 'https://evil/x', 'a//b', str_repeat('a', 300)]);

// Abuse case 7: a redirect from the file endpoint is not followed.
it('does not follow a 302 from the file endpoint: 502, a single request, no Location leaked', function () {
    careersTelegram(responses: ['file' => Http::response('', 302, ['Location' => 'https://evil.example/x.pdf'])]);
    $application = abuseForwardedCv();

    $response = abuseDownload($application)->assertStatus(502);

    expect(abuseFileRequests())->toBe(1)
        ->and(implode(' ', abuseRequestedUrls()))->not->toContain('evil.example')
        ->and($response->headers->get('Location'))->toBeNull()
        ->and($response->getContent())->not->toContain('evil.example')
        ->and($application->activities()->where('type', ActivityType::CvDownload->value)->count())->toBe(0);
});

// Abuse case 8: a body without Content-Length over the limit is cut off.
it('aborts a 6 MB body that has no Content-Length: 502, no bytes served, no Activity', function () {
    careersTelegram(responses: ['file' => Http::response('%PDF-'.str_repeat('A', 6 * 1024 * 1024), 200)]);
    $application = abuseForwardedCv();

    $response = abuseDownload($application)->assertStatus(502);

    expect($response->getContent())->not->toContain('%PDF-')->not->toContain('AAAAAAAA')
        ->and($response->headers->get('Content-Disposition'))->toBeNull()
        ->and($application->activities()->where('type', ActivityType::CvDownload->value)->count())->toBe(0);
});

// Abuse case 9: Telegram reports another file than the stored one.
it('answers 404 without fetching bytes or logging a download when file_unique_id differs', function () {
    careersTelegram(responses: ['getFile' => Http::response(['ok' => true, 'result' => ['file_unique_id' => 'SOMEONE_ELSES', 'file_path' => 'documents/file_9.pdf']])]);
    $application = abuseForwardedCv();

    $response = abuseDownload($application)->assertNotFound();

    expect(abuseFileRequests())->toBe(0)
        ->and($response->getContent())->not->toContain('%PDF')
        ->and(Activity::query()->count())->toBe(0);
});

// Abuse case 10: only Admin may download; careers.cv alone opens nothing else.
it('forbids every other role the download, the Show screen and its save/delete, even with careers.cv granted', function (Role $role) {
    $application = abuseForwardedCv(['internal_note' => 'keep']);
    $application->activities()->create(['type' => ActivityType::Created, 'created_at' => now()]);
    $user = staffUser($role);
    $user->givePermissionTo('careers.cv');
    $admin = staffUser();

    $this->actingAs($user)->get(route('admin.careers.applications.cv', $application->id))->assertForbidden();
    $this->actingAs($user)->get(route('admin.careers.applications.show', $application->id))->assertForbidden();
    Livewire::actingAs($user)->test(Show::class, ['jobApplication' => $application->id])->assertForbidden();

    // Screens opened by an Admin and then driven by a session without the permission: every call is refused.
    $saveScreen = Livewire::actingAs($admin)->test(Show::class, ['jobApplication' => $application->id]);
    $deleteScreen = Livewire::actingAs($admin)->test(Show::class, ['jobApplication' => $application->id]);
    $this->actingAs($user);
    $saveScreen->call('save')->assertForbidden();
    $deleteScreen->call('delete')->assertForbidden();

    // Even with the record permission the download stays Admin-only.
    $user->givePermissionTo('careers.applications');
    $this->actingAs($user->fresh())->get(route('admin.careers.applications.cv', $application->id))->assertForbidden();

    expect($application->fresh()->status)->toBe(JobApplicationStatus::New)
        ->and($application->fresh()->internal_note)->toBe('keep')
        ->and(JobApplication::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1)
        ->and(careersTelegramMethods())->toBe([]);
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

// Abuse case 11: the Show component id is locked.
it('rejects a tampered locked application id on Show and touches no other record', function () {
    $mine = abuseForwardedCv();
    $other = abuseForwardedCv(['name' => 'Other Candidate']);

    $screen = Livewire::actingAs(staffUser())->test(Show::class, ['jobApplication' => $mine->id]);

    expect(fn () => $screen->set('applicationId', $other->id))->toThrow(Exception::class, 'locked');

    $screen->call('delete');

    expect(JobApplication::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(JobApplication::query()->whereKey($mine->id)->exists())->toBeFalse();
});

// Abuse case 12: an application erased while the Telegram send is in flight.
it('deletes the just-sent message once and writes no Activity when erased during the send', function (string $job) {
    $application = $job === 'cv' ? abusePendingCv() : JobApplication::factory()->forVacancy(Vacancy::factory()->published()->create())->create();
    $admin = staffUser();
    $erase = function () use ($application, $admin): void {
        app(DeleteJobApplication::class)->handle(JobApplication::query()->findOrFail($application->id), $admin);
    };
    $method = $job === 'cv' ? 'sendDocument' : 'sendMessage';

    careersTelegram(responses: [
        $method => function () use ($erase) {
            $erase();

            return Http::response(['ok' => true, 'result' => ['message_id' => 901, 'document' => ['file_id' => 'F', 'file_unique_id' => 'U']]]);
        },
    ]);

    $job === 'cv'
        ? (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class))
        : (new NotifyJobApplication($application->id))->handle(app(TelegramClient::class));

    $deletes = collect(telegramApiCalls())->where('method', 'deleteMessage');

    expect($deletes)->toHaveCount(1)
        ->and($deletes->first()['data']['message_id'])->toBe(901)
        ->and(JobApplication::query()->count())->toBe(0)
        ->and(Activity::query()->where('subject_type', 'job_application')->count())->toBe(0)
        ->and(abusePendingFiles())->toBe([]);
})->with(['CV forward' => 'cv', 'text alert' => 'text']);

// Abuse case 13: foreign Host on an oversized POST (TrustHosts is skipped by Laravel in tests, so it is switched on explicitly).
it('answers an oversized POST with a foreign Host with 400 and no foreign Location once the trusted hosts are active', function () {
    $vacancy = Vacancy::factory()->published()->create();
    config(['app.url' => 'https://poson.example.uz']);

    expect(CanonicalHost::trustedHosts())->toBe(['^poson\.example\.uz$', '^www\.poson\.example\.uz$'])
        ->and(app(TrustHosts::class)->hosts())->toBe(CanonicalHost::trustedHosts());

    Request::setTrustedHosts(app(TrustHosts::class)->hosts());

    $path = "/uz/careers/{$vacancy->slug_uz}/apply";

    foreach (['evil.example', 'poson.example.uz.evil.example'] as $host) {
        $response = $this->call('POST', "https://{$host}{$path}", [], [], [], [
            'HTTP_X_FORWARDED_HOST' => $host, 'CONTENT_LENGTH' => 9 * 1024 * 1024 * 1024,
        ]);

        expect($response->getStatusCode())->toBe(400)
            ->and((string) $response->headers->get('Location'))->not->toContain('evil');
    }

    // The canonical host still gets the friendly redirect, built on itself.
    $response = $this->call('POST', 'https://poson.example.uz'.$path, [], [], [], ['CONTENT_LENGTH' => 9 * 1024 * 1024 * 1024]);

    expect($response->getStatusCode())->toBe(302)
        ->and(parse_url((string) $response->headers->get('Location'), PHP_URL_HOST))->toBe('poson.example.uz')
        ->and((string) $response->headers->get('Location'))->toContain('?upload=too_large#careers-apply');
});

it('never redirects an oversized POST by path input: a hostile slug is a plain 413', function (string $path) {
    $response = $this->call('POST', $path, [], [], [], ['CONTENT_LENGTH' => 9 * 1024 * 1024 * 1024]);

    expect($response->getStatusCode())->toBe(413)
        ->and($response->headers->get('Location'))->toBeNull();
})->with([
    '/uz/careers/%2F%2Fevil/apply',
    '/uz/careers/%2F%2Fevil.example%2Fx/apply',
    '/uz/careers/..%2F..%2Fadmin/apply',
    '/xx/careers/some-slug/apply',
    '/uz/careers/apply/nosuchdepartment',
]);

// Abuse case 14: the site-wide hourly application cap.
it('sends no per-application message for the 31st application of an hour: row saved, one cap alert', function () {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();

    foreach (range(1, 31) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.20.{$i}.9"]);
        postVacancyApplication($vacancy, ['phone' => '+99890'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT)])->assertSessionHas('careers_sent');
    }

    $alerts = collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text');

    expect(JobApplication::query()->count())->toBe(31)
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0])->toContain(__('telegram.monitoring.events.careers_cv', [], 'uz'))
        ->and($alerts[0])->not->toContain('Ali')->not->toContain('998');
    Queue::assertPushed(NotifyJobApplication::class, 30);
    Queue::assertNotPushed(ForwardJobApplicationCv::class);
    expect(JobApplication::query()->latest('id')->first()->cv_status)->toBe(JobApplicationCvStatus::None);
});

// Abuse case 15: HTML in the name cannot break the caption.
it('escapes a hostile name in the caption, cuts it to 60 characters and keeps the caption within 1024', function () {
    $application = abusePendingCv();
    $application->forceFill(['name' => '</b><a href="tg://x">'.str_repeat('V', 200)])->save();

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    $caption = careersSentDocuments()[0]['caption'];

    expect($caption)->not->toContain('<a href')->not->toContain('tg://x"')->not->toContain('</b><')
        ->and($caption)->not->toContain(str_repeat('V', 61))
        ->and(JobApplicationMessage::visibleLength($caption))->toBeLessThanOrEqual(1024);
});

it('keeps a hostile name from the public form inert in the sent caption', function () {
    $vacancy = Vacancy::factory()->published()->create();

    postVacancyApplication($vacancy, ['name' => '</b><a href="tg://x">'.str_repeat('V', 90)], careersUpload())->assertSessionHas('careers_sent');

    $caption = careersSentDocuments()[0]['caption'];

    expect($caption)->not->toContain('<a href')->not->toContain('tg://x"')
        ->and($caption)->not->toContain(str_repeat('V', 61))
        ->and(JobApplicationMessage::visibleLength($caption))->toBeLessThanOrEqual(1024);
});

// Abuse case 16: a public group, a channel or an @username is never a target for candidate data.
it('sends nothing but one refused_target alert to a channel, a public group or a private chat', function (array $chat) {
    careersTelegram($chat);
    PrivateGroupTarget::forget();
    $application = abusePendingCv();

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));
    (new NotifyJobApplication($application->id))->handle(app(TelegramClient::class));
    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    $texts = collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text');

    expect(careersTelegramMethods())->not->toContain('sendDocument')
        ->and($texts->filter(fn ($text) => str_contains((string) $text, 'Ali') || str_contains((string) $text, '998')))->toBeEmpty()
        ->and($texts->filter(fn ($text) => str_contains((string) $text, __('telegram.monitoring.careers_reasons.refused_target', [], 'uz'))))->toHaveCount(1)
        ->and($application->fresh()->cv_status)->toBe(JobApplicationCvStatus::Pending);
})->with([
    'channel' => [['id' => -1008888888888, 'type' => 'channel']],
    'public supergroup' => [['id' => -1008888888888, 'type' => 'supergroup', 'username' => 'poson_public']],
    'private chat' => [['id' => 5550001, 'type' => 'private']],
]);

it('sends no candidate data to an @username management chat', function () {
    freshSecrets(['services.telegram.bot_token' => TG_BOT_TOKEN, 'services.telegram.chats.management' => '@public_group']);
    PrivateGroupTarget::forget();
    $application = abusePendingCv();

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));
    (new NotifyJobApplication($application->id))->handle(app(TelegramClient::class));

    $texts = collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text')->implode("\n");

    expect(careersTelegramMethods())->not->toContain('sendDocument')
        ->and($texts)->not->toContain('Ali')->not->toContain('998')
        // Only the counts-only refused_target alert may go out (it carries no candidate data).
        ->and(collect(telegramApiCalls())->where('method', 'sendMessage')->filter(fn (array $call): bool => ! str_contains((string) $call['data']['text'], __('telegram.monitoring.careers_reasons.refused_target', [], 'uz'))))->toBeEmpty();
});

// Abuse case 17: no candidate data, token, file path or API URL in any log line.
it('logs no phone, email, name, token, file_path or API URL across submit, forward failure and download failure', function () {
    $lines = [];
    $spy = Log::spy();
    $spy->shouldReceive('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug')
        ->andReturnUsing(function (...$arguments) use (&$lines): void {
            $lines[] = json_encode(array_map(fn ($argument) => $argument instanceof Throwable ? $argument::class.$argument->getMessage() : $argument, $arguments), JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

    $vacancy = Vacancy::factory()->published()->create();
    $token = careersToken(['vacancy_id' => $vacancy->id]);

    // Submit: success, invalid fields, a bot and a storage failure.
    postVacancyApplication($vacancy, ['form_token' => $token, 'email' => 'secret@example.uz'])->assertSessionHas('careers_sent');
    postVacancyApplication($vacancy, ['name' => '', 'email' => 'secret@example.uz']);
    postVacancyApplication($vacancy, ['fax_ext' => 'bot']);
    JobApplication::creating(fn () => throw new RuntimeException('db down for +998901234567 secret@example.uz Ali'));
    $this->travel(11)->minutes();
    RateLimiter::clear('careers-ip:'.sha1('127.0.0.1'));
    postVacancyApplication($vacancy, ['phone' => '+998 91 555 66 77'], careersUpload());
    JobApplication::flushEventListeners();

    // Forward failure.
    careersTelegram(responses: ['sendDocument' => Http::response(['ok' => false], 500)]);
    $pending = abusePendingCv();

    try {
        (new ForwardJobApplicationCv($pending->id))->handle(app(TelegramClient::class));
    } catch (Throwable) {
    }

    // Download failures.
    $forwarded = abuseForwardedCv(['name' => 'Zarina Candidate']);
    careersTelegram(responses: ['getFile' => Http::response(['ok' => false], 500)]);
    abuseDownload($forwarded)->assertStatus(502);
    careersTelegram(responses: ['getFile' => Http::response(['ok' => true, 'result' => ['file_unique_id' => 'AgADFAKE', 'file_path' => '../../etc/passwd']])]);
    abuseDownload($forwarded)->assertNotFound();
    careersTelegram(responses: ['file' => Http::response('', 302, ['Location' => 'https://evil.example/x'])]);
    abuseDownload($forwarded)->assertStatus(502);

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect($line)->not->toContain('Ali')->not->toContain('Zarina')->not->toContain('998')->not->toContain('secret@')
            ->not->toContain(TG_BOT_TOKEN)->not->toContain('AAFakeToken')->not->toContain('api.telegram.org')
            ->not->toContain('file_7.pdf')->not->toContain('etc/passwd')->not->toContain('BQACAgIAAxkBAAIFAKE')
            ->not->toContain('evil.example')->not->toContain($token);
    }
});

// Abuse case 18: kill switches.
it('refuses a valid token with a form error while the kill switch is off: no row, no file, no Telegram', function (string $switch, string $target) {
    Queue::fake();
    $vacancy = Vacancy::factory()->published()->create();
    Setting::query()->create(['key' => "careers.{$switch}", 'group' => 'careers', 'value' => false]);
    CareersSwitch::forget();

    $response = $target === 'vacancy'
        ? postVacancyApplication($vacancy, [], careersUpload())
        : postOpenApplication('sales', [], careersUpload());

    $response->assertSessionHasErrors(['form'], errorBag: 'careers')->assertSessionMissing('careers_sent');

    abuseAssertNothingStored();
    expect(RateLimiter::attempts('careers-ip:'.sha1('127.0.0.1')))->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'applications off, vacancy' => ['applications_enabled', 'vacancy'],
    'applications off, open application' => ['applications_enabled', 'open'],
    'open applications off, open application' => ['open_applications_enabled', 'open'],
]);
