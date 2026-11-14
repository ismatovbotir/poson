<?php

use App\Actions\Careers\DeleteJobApplication;
use App\Enums\ActivityType;
use App\Enums\JobApplicationCvStatus;
use App\Enums\JobApplicationStatus;
use App\Enums\Role;
use App\Enums\VacancyDepartment;
use App\Livewire\Admin\Careers\Applications;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Policies\JobApplicationPolicy;
use App\Support\Careers\PendingCv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/Careers.php';

beforeEach(function () {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function forwardedApplication(array $attributes = []): JobApplication
{
    return JobApplication::factory()->create(array_replace([
        'cv_status' => JobApplicationCvStatus::Forwarded,
        'cv_file_id' => 'BQACAgIAAxkBAAIFAKE',
        'cv_file_unique_id' => 'AgADFAKE',
        'cv_original_name' => 'Ali Valiyev — CV.pdf',
        'cv_mime' => 'application/pdf',
        'cv_size' => 120,
        'cv_forwarded_at' => now(),
        'telegram_message_id' => 701,
    ], $attributes));
}

it('refuses the CV download to every other role, even with the permission granted by mistake', function () {
    careersTelegram();
    $application = forwardedApplication();

    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        $this->actingAs(staffUser($role))->get(route('admin.careers.applications.cv', $application->id))->assertForbidden();
    }

    $sales = staffUser(Role::SalesManager);
    $sales->givePermissionTo('careers.cv', 'careers.applications');
    expect((new JobApplicationPolicy)->downloadCv($sales->fresh(), $application))->toBeFalse();

    expect(careersTelegramMethods())->toBe([]);
});

it('checks every application policy ability against careers.applications', function (string $ability) {
    $application = JobApplication::factory()->create();
    $policy = new JobApplicationPolicy;

    expect($policy->{$ability}(staffUser(Role::Admin), $application))->toBeTrue()
        ->and($policy->{$ability}(staffUser(Role::SalesManager), $application))->toBeFalse()
        ->and($policy->{$ability}(staffUser(Role::ContentManager), $application))->toBeFalse();
})->with(['view', 'update', 'delete', 'downloadCv']);

it('filters by vacancy, open application, department, status, CV state and searches name or phone', function () {
    $vacancy = Vacancy::factory()->published()->create(['department' => VacancyDepartment::Service]);
    JobApplication::factory()->forVacancy($vacancy)->create(['name' => 'Ali Valiyev', 'phone' => '+998901112233']);
    JobApplication::factory()->openApplication(VacancyDepartment::Marketing)->create(['name' => 'Bobur Karimov', 'phone' => '+998935556677', 'status' => JobApplicationStatus::Interview]);
    JobApplication::factory()->openApplication(VacancyDepartment::Sales)->create(['name' => 'Dilnoza Rahimova', 'phone' => '+998977778899', 'cv_status' => JobApplicationCvStatus::Expired]);

    $screen = Livewire::actingAs(staffUser())->test(Applications\Index::class)
        ->assertSee('Ali Valiyev')->assertSee('Bobur Karimov')->assertSee('Dilnoza Rahimova');

    $screen->set('vacancy', (string) $vacancy->id)->assertSee('Ali Valiyev')->assertDontSee('Bobur Karimov')
        ->set('vacancy', 'open')->assertDontSee('Ali Valiyev')->assertSee('Bobur Karimov')
        ->call('clearFilters')
        ->set('department', 'marketing')->assertSee('Bobur Karimov')->assertDontSee('Dilnoza Rahimova')
        ->call('clearFilters')
        ->set('status', 'interview')->assertSee('Bobur Karimov')->assertDontSee('Ali Valiyev')
        ->call('clearFilters')
        ->set('cvStatus', 'expired')->assertSee('Dilnoza Rahimova')->assertDontSee('Ali Valiyev')
        ->call('clearFilters')
        ->set('search', 'bobur')->assertSee('Bobur Karimov')->assertDontSee('Ali Valiyev')
        ->set('search', '935556')->assertSee('Bobur Karimov')->assertDontSee('Dilnoza Rahimova')
        ->set('search', 'zzz-nobody')->assertSee(__('admin.catalog.no_results'))
        ->assertSee(__('careers.admin.applications.status'));
});

it('lists without N+1 queries', function () {
    JobApplication::factory()->count(5)->create();
    Model::preventLazyLoading();

    Livewire::actingAs(staffUser())->test(Applications\Index::class)->assertOk();
});

it('shows the application without the Telegram file id or the pending path', function () {
    $application = forwardedApplication(['cv_pending_path' => 'careers/pending/01JABCDEFGHJKMNPQRSTVWXYZ0.bin', 'message' => "Line one\nLine two"]);

    $component = Livewire::actingAs(staffUser())->test(Applications\Show::class, ['jobApplication' => $application->id])
        ->assertSee('#J-'.$application->id)->assertSee('Line one')
        ->assertSee(__('careers.admin.applications.download_cv'))
        ->assertDontSee('BQACAgIAAxkBAAIFAKE')->assertDontSee('AgADFAKE')->assertDontSee('careers/pending');

    expect(json_encode($component->snapshot))->not->toContain('BQACAgIAAxkBAAIFAKE')->not->toContain('careers/pending');
});

it('offers "Download CV" only for a forwarded CV', function () {
    $application = forwardedApplication(['cv_status' => JobApplicationCvStatus::Expired]);

    Livewire::actingAs(staffUser())->test(Applications\Show::class, ['jobApplication' => $application->id])
        ->assertSee(JobApplicationCvStatus::Expired->label())
        ->assertDontSee(__('careers.admin.applications.download_cv'));
});

it('changes the status and the internal note through the action with activities', function () {
    $application = JobApplication::factory()->create();

    Livewire::actingAs($admin = staffUser())->test(Applications\Show::class, ['jobApplication' => $application->id])
        ->set('status', 'interview')
        ->set('internalNote', "<b>Call</b> on Monday\u{200B}")
        ->call('save')
        ->assertHasNoErrors();

    $application->refresh();

    expect($application->status)->toBe(JobApplicationStatus::Interview)
        ->and($application->internal_note)->toBe('Call on Monday')
        ->and($application->status_changed_at)->not->toBeNull()
        ->and($application->activities()->where('type', ActivityType::StatusChange->value)->sole()->meta)->toBe(['from' => 'new', 'to' => 'interview'])
        ->and($application->activities()->where('type', ActivityType::Note->value)->count())->toBe(1);

    Livewire::actingAs($admin)->test(Applications\Show::class, ['jobApplication' => $application->id])
        ->set('status', 'nonsense')->call('save')->assertHasErrors('status');
});

it('erases the row, activities and pending file and deletes a fresh Telegram copy', function () {
    careersTelegram();
    $application = forwardedApplication(['cv_status' => JobApplicationCvStatus::Pending, 'cv_pending_path' => PendingCv::write(careersPdf())]);
    $application->activities()->create(['type' => ActivityType::Created, 'created_at' => now()]);
    $path = $application->cv_pending_path;

    Livewire::actingAs(staffUser())->test(Applications\Show::class, ['jobApplication' => $application->id])
        ->call('delete')
        ->assertRedirect(route('admin.careers.applications.index'));

    expect(JobApplication::query()->count())->toBe(0)
        ->and(Activity::query()->where('subject_type', 'job_application')->count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(careersTelegramMethods())->toBe(['deleteMessage']);
});

it('leaves a Telegram copy older than 48 hours to the manual clean-up', function () {
    careersTelegram();
    $application = forwardedApplication(['cv_forwarded_at' => now()->subHours(49)]);

    app(DeleteJobApplication::class)->handle($application, staffUser());

    expect(JobApplication::query()->count())->toBe(0)->and(careersTelegramMethods())->toBe([]);
});

it('streams the CV with the CR-8 headers and logs the download', function () {
    careersTelegram();
    $application = forwardedApplication();

    $response = $this->actingAs($admin = staffUser())->get(route('admin.careers.applications.cv', $application->id))->assertOk();

    expect($response->getContent())->toStartWith('%PDF-')->not->toContain(TG_BOT_TOKEN)
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename=CV-'.$application->id.'.pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain("filename*=utf-8''")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($application->activities()->where('type', ActivityType::CvDownload->value)->sole()->user_id)->toBe($admin->id);
});

it('answers a generic 404 / 502 when Telegram fails or the file does not match', function (array $responses, ?array $attributes, int $status) {
    // As in production (no debug page with a stack trace).
    config(['app.debug' => false]);
    careersTelegram(responses: array_map(fn (array $spec) => Http::response(...$spec), $responses));
    $application = forwardedApplication($attributes ?? []);

    $response = $this->actingAs(staffUser())->get(route('admin.careers.applications.cv', $application->id))->assertStatus($status);

    expect($response->getContent())->not->toContain(TG_BOT_TOKEN)->not->toContain('api.telegram.org')
        ->and($application->activities()->where('type', ActivityType::CvDownload->value)->count())->toBe(0);
})->with([
    'not forwarded' => [[], ['cv_status' => JobApplicationCvStatus::Failed], 404],
    'other file_unique_id' => [['getFile' => [['ok' => true, 'result' => ['file_unique_id' => 'OTHER', 'file_path' => 'documents/file_7.pdf']]]], null, 404],
    'not a pdf' => [['file' => ['MZ executable']], null, 404],
    'getFile 400' => [['getFile' => [['ok' => false], 400]], null, 404],
    'getFile 500' => [['getFile' => [['ok' => false], 500]], null, 502],
    'mime not allowed' => [[], ['cv_mime' => 'text/html'], 404],
]);

it('keeps job applications out of the dashboard and the menu of other roles (CR-11)', function () {
    $application = JobApplication::factory()->create(['name' => 'Zarina Candidate']);
    $application->activities()->create(['type' => ActivityType::Created, 'created_at' => now()]);

    $this->actingAs(staffUser(Role::SalesManager))->get(route('admin.dashboard'))->assertOk()->assertDontSee('Zarina Candidate');
    $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.dashboard'))->assertOk()->assertDontSee('Zarina Candidate');
});

it('shows the manual clean-up help and the Integrations hint about CVs', function () {
    Livewire::actingAs(staffUser())->test(Applications\Index::class)->assertSee(__('careers.admin.help.title'));

    expect(__('admin.settings.integrations.hints.telegram_chats_management', [], 'en'))->toContain('CVs')->and(__('admin.settings.integrations.hints.telegram_chats_management', [], 'uz'))->toContain('rezyume')->and(__('admin.settings.integrations.hints.telegram_chats_management', [], 'ru'))->toContain('резюме');
});
