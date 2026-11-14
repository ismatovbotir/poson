<?php

use App\Enums\ActivityType;
use App\Enums\JobApplicationCvStatus;
use App\Enums\VacancyDepartment;
use App\Jobs\ForwardJobApplicationCv;
use App\Jobs\NotifyJobApplication;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Careers\CareersCaps;
use App\Support\Careers\JobApplicationMessage;
use App\Support\Careers\PendingCv;
use App\Support\Telegram\PrivateGroupTarget;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Helpers/Careers.php';

beforeEach(function () {
    Storage::fake('local');
});

/**
 * An application with a pending encrypted CV (as SubmitJobApplication leaves it).
 *
 * @param  array<string, mixed>  $attributes
 */
function pendingApplication(array $attributes = []): JobApplication
{
    $vacancy = Vacancy::factory()->published()->create(['title' => ['uz' => 'Servis muhandisi', 'ru' => 'Сервисный инженер', 'en' => 'Service engineer']]);

    return JobApplication::factory()->forVacancy($vacancy)->create(array_replace([
        'name' => 'Ali Valiyev',
        'email' => 'ali@example.uz',
        'message' => 'Secret motivation text',
        'cv_status' => JobApplicationCvStatus::Pending,
        'cv_pending_path' => PendingCv::write(careersPdf()),
        'cv_original_name' => 'my-cv-file.pdf',
        'cv_mime' => 'application/pdf',
        'cv_size' => 120,
    ], $attributes));
}

it('forwards the CV as a multipart document with the CR-10 caption, stores the ids and deletes the pending file', function () {
    careersTelegram();
    $application = pendingApplication(['name' => '</b><a href="x">Ali</a> & "Co" '.str_repeat('V', 80)]);
    $path = $application->cv_pending_path;

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    $application->refresh();
    $documents = careersSentDocuments();
    $caption = $documents[0]['caption'];

    expect($application->cv_status)->toBe(JobApplicationCvStatus::Forwarded)
        ->and($application->cv_file_id)->toBe('BQACAgIAAxkBAAIFAKE')
        ->and($application->cv_file_unique_id)->toBe('AgADFAKE')
        ->and($application->telegram_message_id)->toBe(701)
        ->and($application->cv_forwarded_at)->not->toBeNull()
        ->and($application->cv_pending_path)->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($documents[0]['chat_id'])->toBe(TG_MANAGEMENT_CHAT)
        ->and($documents[0]['filename'])->toBe('CV-'.$application->id.'.pdf')
        ->and($documents[0]['document'])->toStartWith('%PDF-')
        ->and($documents[0]['parse_mode'])->toBe('HTML')
        ->and($caption)->toContain('#J-'.$application->id)
        ->and($caption)->toContain('Servis muhandisi')
        ->and($caption)->toContain('+998 (90) 123-45-67')
        ->and($caption)->toContain('Ali &amp; &quot;Co&quot;')
        ->and($caption)->not->toContain('<a href')
        ->and($caption)->not->toContain('ali@example.uz')
        ->and($caption)->not->toContain('Secret motivation')
        ->and($caption)->not->toContain('my-cv-file')
        ->and($caption)->not->toContain(str_repeat('V', 61))
        ->and(JobApplicationMessage::visibleLength($caption))->toBeLessThanOrEqual(1024)
        ->and(json_decode($documents[0]['reply_markup'], true)['inline_keyboard'][0][0]['url'])->toBe('https://poson.example.uz/admin/careers/applications/'.$application->id)
        ->and($application->activities()->where('type', ActivityType::Notification->value)->count())->toBe(1);
});

it('claims atomically: a second run or a non-pending row sends nothing', function () {
    careersTelegram();
    $application = pendingApplication(['cv_status' => JobApplicationCvStatus::Sending]);

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    expect(careersTelegramMethods())->toBe([]);
});

it('sends nothing once the application was deleted before the job ran', function () {
    careersTelegram();
    Queue::fake();
    $application = pendingApplication();
    $job = new ForwardJobApplicationCv($application->id);
    $notify = new NotifyJobApplication($application->id);
    $application->delete();

    $job->handle(app(TelegramClient::class));
    $notify->handle(app(TelegramClient::class));

    expect(careersTelegramMethods())->toBe([]);
});

it('carries only the id in the job payloads', function () {
    $application = pendingApplication();

    foreach ([new ForwardJobApplicationCv($application->id), new NotifyJobApplication($application->id, true)] as $job) {
        $payload = serialize($job);

        expect($payload)->not->toContain('Ali')->not->toContain('998')->not->toContain('careers/pending')->not->toContain('example.uz');
    }
});

it('puts the CV back to pending on 5xx and on 429 (released), never sending a fallback', function (int $status) {
    careersTelegram(responses: ['sendDocument' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 7]], $status)]);
    Queue::fake();
    $application = pendingApplication();
    $job = (new ForwardJobApplicationCv($application->id))->withFakeQueueInteractions();

    try {
        $job->handle(app(TelegramClient::class));
    } catch (TelegramException) {
    }

    expect($application->fresh()->cv_status)->toBe(JobApplicationCvStatus::Pending)
        ->and(Storage::disk('local')->exists($application->cv_pending_path))->toBeTrue();
    Queue::assertNotPushed(NotifyJobApplication::class);

    if ($status === 429) {
        $job->assertReleased(7);
    }
})->with([429, 500, 502]);

it('fails on 400 / 403: pending file deleted, text fallback queued, counts-only alert', function (int $status) {
    careersTelegram(responses: ['sendDocument' => Http::response(['ok' => false], $status)]);
    Queue::fake([NotifyJobApplication::class]);
    $application = pendingApplication();

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    $alerts = collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text');

    expect($application->fresh()->cv_status)->toBe(JobApplicationCvStatus::Failed)
        ->and($application->fresh()->cv_pending_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0])->not->toContain('Ali');
    Queue::assertPushed(NotifyJobApplication::class, fn (NotifyJobApplication $job): bool => $job->jobApplicationId === $application->id && $job->cvNotDelivered);
})->with([400, 403]);

it('fails with a text fallback when the pending copy cannot be decrypted (APP_KEY rotated)', function () {
    careersTelegram();
    Queue::fake([NotifyJobApplication::class]);
    $application = pendingApplication();
    Storage::disk('local')->put($application->cv_pending_path, (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('x'));

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));

    expect($application->fresh()->cv_status)->toBe(JobApplicationCvStatus::Failed)
        ->and(careersTelegramMethods())->not->toContain('sendDocument');
    Queue::assertPushed(NotifyJobApplication::class);
});

it('refuses a channel, a public group or an @username target: nothing sent, refused_target alert', function (array $chat, ?string $chatValue) {
    careersTelegram($chat);

    if ($chatValue !== null) {
        freshSecrets([
            'services.telegram.bot_token' => TG_BOT_TOKEN,
            'services.telegram.chats.management' => $chatValue,
        ]);
    }

    PrivateGroupTarget::forget();
    $application = pendingApplication();

    (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));
    (new NotifyJobApplication($application->id))->handle(app(TelegramClient::class));

    $methods = careersTelegramMethods();

    expect($methods)->not->toContain('sendDocument')
        ->and($application->fresh()->cv_status)->toBe(JobApplicationCvStatus::Pending)
        ->and(collect(telegramApiCalls())->where('method', 'sendMessage')->filter(fn ($call) => str_contains((string) $call['data']['text'], 'Ali')))->toBeEmpty();

    // The counts-only refused_target alert goes out through MonitoringAlerts (skipped for an @username chat value).
    if ($chatValue === null) {
        expect(collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text')->implode("\n"))
            ->toContain(__('telegram.monitoring.careers_reasons.refused_target', [], 'uz'));
    }
})->with([
    'channel' => [['id' => -1008888888888, 'type' => 'channel'], null],
    'public supergroup' => [['id' => -1008888888888, 'type' => 'supergroup', 'username' => 'poson_public'], null],
    'private chat with a person' => [['id' => 5550001, 'type' => 'private'], null],
    '@username value' => [['type' => 'supergroup'], '@poson_team'],
]);

it('sends the text-only message for an open application with the department line and no email or message', function () {
    careersTelegram();
    $application = JobApplication::factory()->openApplication(VacancyDepartment::Marketing)->create(['email' => 'x@example.uz', 'message' => 'Hidden text']);

    (new NotifyJobApplication($application->id, true))->handle(app(TelegramClient::class));

    $text = collect(telegramApiCalls())->firstWhere('method', 'sendMessage')['data']['text'];

    expect($text)->toContain('#J-'.$application->id)
        ->and($text)->toContain(__('careers.telegram.open_application', ['department' => VacancyDepartment::Marketing->label()], 'uz'))
        ->and($text)->toContain(__('careers.telegram.cv_not_delivered', [], 'uz'))
        ->and($text)->not->toContain('x@example.uz')
        ->and($text)->not->toContain('Hidden text')
        ->and($application->fresh()->telegram_message_id)->toBe(702);
});

it('expires CVs older than 24 hours, recovers stuck sends and sweeps orphan files', function () {
    careersTelegram();
    Queue::fake();
    $old = pendingApplication();
    $old->forceFill(['created_at' => now()->subHours(25)])->saveQuietly();
    $stuck = pendingApplication(['cv_status' => JobApplicationCvStatus::Sending]);
    JobApplication::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(20)]);
    $fresh = pendingApplication();

    $orphan = CareersCaps::PENDING_DIR.'/01JORPHAN0000000000000000.bin';
    Storage::disk('local')->put($orphan, 'x');
    touch(Storage::disk('local')->path($orphan), now()->subHours(30)->getTimestamp());
    $youngOrphan = PendingCv::write('y');

    $this->artisan('careers:expire-pending-cvs')->assertSuccessful();

    expect($old->fresh()->cv_status)->toBe(JobApplicationCvStatus::Expired)
        ->and($old->fresh()->cv_pending_path)->toBeNull()
        ->and(Storage::disk('local')->exists($old->cv_pending_path))->toBeFalse()
        ->and($stuck->fresh()->cv_status)->toBe(JobApplicationCvStatus::Pending)
        ->and($fresh->fresh()->cv_status)->toBe(JobApplicationCvStatus::Pending)
        ->and(Storage::disk('local')->exists($fresh->cv_pending_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($orphan))->toBeFalse()
        ->and(Storage::disk('local')->exists($youngOrphan))->toBeTrue();

    Queue::assertPushed(NotifyJobApplication::class, fn ($job) => $job->jobApplicationId === $old->id && $job->cvNotDelivered);
    Queue::assertPushed(ForwardJobApplicationCv::class, fn ($job) => $job->jobApplicationId === $stuck->id);

    $alerts = collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text');
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toContain(__('telegram.monitoring.careers_reasons.expired', [], 'uz'))->not->toContain('Ali');

    $this->artisan('careers:expire-pending-cvs')->assertSuccessful();
    expect(collect(telegramApiCalls())->where('method', 'sendMessage'))->toHaveCount(1);
});

it('registers the expire command hourly on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'careers:expire-pending-cvs'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('logs a forward failure with the id and code only', function () {
    Log::spy();
    careersTelegram(responses: ['sendDocument' => Http::response(['ok' => false], 500)]);
    $application = pendingApplication();

    try {
        (new ForwardJobApplicationCv($application->id))->handle(app(TelegramClient::class));
    } catch (TelegramException) {
    }

    Log::shouldHaveReceived('warning')->once()->with('Careers CV forward failed', ['job_application' => $application->id, 'code' => '500']);
});
