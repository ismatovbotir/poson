<?php

use App\Actions\Office\PromoteFeedbackToExample;
use App\Actions\Office\PruneBusinessData;
use App\Actions\Office\RecordDraftFeedback;
use App\Actions\Office\SaveAgentProfileVersion;
use App\Enums\AgentRole;
use App\Enums\AuditAction;
use App\Enums\DraftOutcome;
use App\Enums\Role;
use App\Livewire\Admin\Office\EducationReview;
use App\Livewire\Admin\Office\Groups\Show as GroupShow;
use App\Models\AgentProfile;
use App\Models\OfficeDraftFeedback;
use App\Models\SettingsAudit;
use App\Models\TelegramGroupThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';
require_once __DIR__.'/DraftFeedbackTest.php';

/*
| Plan 46 T6: the review screen, its metrics and Promote (ED-1, ED-5), plus the T5 hardening (races, pruned drafts).
*/

function promotable(array $feedback = []): OfficeDraftFeedback
{
    officeAgents();
    $f = feedbackChat();
    $f['chat']->forceFill(['first_name' => 'Zarnigor', 'username' => 'fake_zarnigor'])->save();

    return OfficeDraftFeedback::factory()->edited('Tushunarli, qaysi oʻlcham kerak?')->create(array_merge(['business_chat_id' => $f['chat']->id, 'business_chat_analysis_id' => $f['analysis']->id, 'agent_version_id' => AgentProfile::forRole(AgentRole::Sales)->current_version_id, 'role' => AgentRole::Sales], $feedback));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoteData(array $overrides = []): array
{
    return array_merge(['kind' => 'good', 'language' => 'uz', 'client' => 'Menga yorliq printer kerak.', 'reply' => 'Qaysi oʻlcham va nechta yorliq kerak?', 'note' => 'Asks first', 'anonymised' => true, 'password' => 'password'], $overrides);
}

it('creates a NEW unpublished version with the example appended and the source feedback id, and audits ids only', function () {
    $feedback = promotable();
    $admin = staffUser();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $published = $profile->current_version_id;
    $before = count((array) $profile->currentVersion->examples);

    $version = app(PromoteFeedbackToExample::class)->handle($admin, $feedback, promoteData(['kind' => 'bad']), '127.0.0.1');

    $example = collect($version->examples)->last();

    expect($profile->fresh()->current_version_id)->toBe($published)
        ->and($version->id)->not->toBe($published)
        ->and($version->change_note)->toBe('Example from feedback #'.$feedback->id)
        ->and($version->examples)->toHaveCount($before + 1)
        ->and($example)->toMatchArray(['kind' => 'bad', 'language' => 'uz', 'source_feedback_id' => $feedback->id])
        ->and($version->instructions)->toBe($profile->currentVersion->instructions)
        ->and($feedback->fresh()->promoted_version_id)->toBe($version->id)
        ->and($feedback->fresh()->promoted_by_id)->toBe($admin->id);

    $audit = SettingsAudit::query()->where('action', AuditAction::Promoted->value)->sole();

    expect($audit->new_text)->toBe('feedback='.$feedback->id.' version=v'.$version->version)->not->toContain('Menga');
});

it('respects the kind caps, refusing with a clear message', function (string $kind, int $cap) {
    $feedback = promotable();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $versions = $profile->versions()->count();
    DB::table('agent_profile_versions')->where('id', $profile->current_version_id)->update(['examples' => json_encode(array_fill(0, $cap, ['kind' => $kind, 'language' => 'uz', 'client' => 'a', 'reply' => 'b']))]);

    expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData(['kind' => $kind])))->toThrow(ValidationException::class, __('office.review.errors.cap_full', ['kind' => __('office.education.kind_'.$kind)]))
        ->and(AgentProfile::forRole(AgentRole::Sales)->versions()->count())->toBe($versions);
})->with([['good', 6], ['bad', 4]]);

it('refuses a total over 10 examples and a second promotion of the same feedback', function () {
    $feedback = promotable();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    DB::table('agent_profile_versions')->where('id', $profile->current_version_id)->update(['examples' => json_encode(array_merge(array_fill(0, 5, ['kind' => 'good', 'client' => 'a', 'reply' => 'b']), array_fill(0, 3, ['kind' => 'bad', 'client' => 'a', 'reply' => 'b']), array_fill(0, 2, ['kind' => 'route', 'client' => 'a', 'role' => 'sales'])))]);

    expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData()))->toThrow(ValidationException::class);

    DB::table('agent_profile_versions')->where('id', $profile->current_version_id)->update(['examples' => json_encode([])]);
    app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData());

    expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback->fresh(), promoteData()))->toThrow(ValidationException::class);
});

it('pre-fills the form from the redacted final text and promotes from the screen, never publishing', function () {
    $feedback = promotable();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $published = $profile->current_version_id;
    $versions = $profile->versions()->count();

    Livewire::actingAs(staffUser())->test(EducationReview::class)
        ->call('startPromote', $feedback->id)
        ->assertSet('reply', 'Tushunarli, qaysi oʻlcham kerak?')
        ->assertSet('client', '')
        ->set('client', 'Menga yorliq printer kerak.')
        ->set('anonymised', true)
        ->set('password', 'password')
        ->call('promote')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertSet('promoteId', null);

    expect($profile->fresh()->current_version_id)->toBe($published)->and($profile->versions()->count())->toBe($versions + 1);
});

it('starts a rejected draft as a bad example with the draft text', function () {
    $feedback = promotable(['outcome' => DraftOutcome::Rejected, 'final_text' => null, 'draft_text' => 'Bad draft text']);

    Livewire::actingAs(staffUser())->test(EducationReview::class)->call('startPromote', $feedback->id)->assertSet('kind', 'bad')->assertSet('reply', 'Bad draft text');
});

it('lists feedback with filters, opens the decrypted texts on demand and shows counts only', function () {
    $feedback = promotable(['note' => 'Secret note text']);
    OfficeDraftFeedback::factory()->rejected()->create(['role' => AgentRole::Service]);
    OfficeDraftFeedback::factory()->create();

    $page = Livewire::actingAs(staffUser())->test(EducationReview::class)
        ->assertSeeHtml('data-feedback="'.$feedback->id.'"')
        ->assertDontSee('Secret note text')
        ->call('open', $feedback->id)
        ->assertSee('Secret note text')
        ->assertSee('Tushunarli, qaysi')
        ->set('role', 'service')
        ->assertDontSeeHtml('data-feedback="'.$feedback->id.'"')
        ->set('role', '')
        ->set('outcome', 'used')
        ->assertDontSeeHtml('data-feedback="'.$feedback->id.'"')
        ->set('outcome', '')
        ->assertSeeHtml('data-unmatched-link')
        ->call('setTab', 'metrics')
        ->assertSeeHtml('data-metrics');

    $html = $page->html();

    expect($html)->not->toMatch('/\bavg\b|median|в среднем|oʻrtacha|\baverage\b/iu')
        ->and($page->viewData('metrics')['outcomes'])->toMatchArray(['edited' => 1, 'rejected' => 1, 'used' => 1])
        ->and($page->viewData('metrics')['byRole']['sales']['edited'])->toBe(1);
});

it('has no average anywhere in the review sources', function () {
    $sources = file_get_contents(app_path('Livewire/Admin/Office/EducationReview.php')).file_get_contents(resource_path('views/livewire/admin/office/education-review.blade.php'));

    expect(strtolower($sources))->not->toContain('avg(')->not->toContain('->avg(')->not->toContain('median')->not->toContain('/ count(');
});

it('is Admin only: sales, service, content managers get 403, the Admin gets the screen', function (Role $role) {
    Livewire::actingAs(staffUser($role))->test(EducationReview::class)->assertForbidden();
    $this->actingAs(staffUser($role))->get(route('admin.office.education.review'))->assertForbidden();
})->with([Role::SalesManager, Role::ServiceEngineer, Role::ContentManager]);

it('opens for the Admin and is linked from Education', function () {
    $this->actingAs(staffUser())->get(route('admin.office.education.review'))->assertOk()->assertSee(__('office.review.title'));
    $this->actingAs(staffUser())->get(route('admin.office.education', 'sales'))->assertSee('data-review-link', false);
});

it('T5 hardening: two submits for one draft leave exactly one row and no error', function () {
    $f = feedbackChat();
    $injected = false;

    OfficeDraftFeedback::creating(function () use (&$injected, $f): void {
        if (! $injected) {
            $injected = true;
            DB::table('office_draft_feedback')->insert(['business_chat_id' => $f['chat']->id, 'business_chat_analysis_id' => $f['analysis']->id, 'role' => 'sales', 'outcome' => 'used', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $row = app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'rejected', 'reason' => 'tone', 'analysis_id' => $f['analysis']->id]);

    expect($injected)->toBeTrue()
        ->and(OfficeDraftFeedback::query()->count())->toBe(1)
        ->and($row->fresh()->outcome)->toBe(DraftOutcome::Rejected);
});

it('T5 hardening: drops feedback keys that are not a thread on screen', function () {
    $g = feedbackGroup();

    Livewire::actingAs($g['sales'])->test(GroupShow::class, ['group' => $g['group']])
        ->set('feedback.99999.outcome', 'used')
        ->set('feedback.'.$g['thread']->id.'.outcome', 'used')
        ->assertSet('feedback', [$g['thread']->id => ['outcome' => 'used']]);
});

it('T5 hardening: a deleted thread leaves an orphan row that the same prune clears', function () {
    $g = feedbackGroup();
    $row = OfficeDraftFeedback::factory()->edited('Group final')->create(['business_chat_id' => null, 'business_chat_analysis_id' => null, 'telegram_group_id' => $g['group']->id, 'telegram_group_thread_id' => $g['thread']->id, 'telegram_group_analysis_id' => $g['analysis']->id, 'note' => 'n']);

    TelegramGroupThread::query()->whereKey($g['thread']->id)->delete();

    expect($row->fresh()->telegram_group_thread_id)->toBeNull()->and($row->fresh()->final_text)->not->toBeNull();

    app(PruneBusinessData::class)->handle();

    expect($row->fresh()->final_text)->toBeNull()->and($row->fresh()->note)->toBeNull();
});

it('T5 hardening: feedback after the draft was pruned is refused', function () {
    $f = feedbackChat();
    $f['analysis']->forceFill([])->getConnection()->table('business_chat_analyses')->where('id', $f['analysis']->id)->update(['created_at' => now()->subDays(40)]);

    app(PruneBusinessData::class)->handle();

    expect(fn () => app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'used', 'analysis_id' => $f['analysis']->id]))->toThrow(ValidationException::class);
});

it('never keeps the typed password in the snapshot after any other action', function (Closure $act) {
    $feedback = promotable();

    $page = Livewire::actingAs(staffUser())->test(EducationReview::class)->call('startPromote', $feedback->id)->set('password', 'TYPED-SECRET-PW');
    $act($page, $feedback);

    expect($page->get('password'))->toBe('')
        ->and(json_encode($page->snapshot ?? $page->lastState->getSnapshot()))->not->toContain('TYPED-SECRET-PW');
})->with([
    'tab' => [fn ($page) => $page->call('setTab', 'metrics')],
    'open' => [fn ($page, $feedback) => $page->call('open', $feedback->id)],
    'filter' => [fn ($page) => $page->set('role', 'sales')],
    'close' => [fn ($page) => $page->call('close')],
    'failed promote' => [fn ($page) => $page->set('anonymised', false)->call('promote')],
]);

it('shows a hint when a newer unpublished version exists, e.g. after a promote', function () {
    $feedback = promotable();
    $admin = staffUser();

    $this->actingAs($admin)->get(route('admin.office.education', 'sales'))->assertDontSee('data-pending-hint', false);

    $version = app(PromoteFeedbackToExample::class)->handle($admin, $feedback, promoteData());

    $this->actingAs($admin)->get(route('admin.office.education', 'sales'))->assertSee('data-pending-hint', false)->assertSee('v'.$version->version);
});

it('gives distinct version numbers to a saved draft and a promote, overwriting nothing', function () {
    $feedback = promotable();
    $admin = staffUser();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $draft = app(SaveAgentProfileVersion::class)->handle($admin, AgentRole::Sales, ['instructions' => 'A saved draft.', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 800]);
    $promoted = app(PromoteFeedbackToExample::class)->handle($admin, $feedback, promoteData());
    $second = app(SaveAgentProfileVersion::class)->handle($admin, AgentRole::Sales, ['instructions' => 'Another draft.', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 800]);

    expect([$draft->version, $promoted->version, $second->version])->toBe([$draft->version, $draft->version + 1, $draft->version + 2])
        ->and($profile->versions()->pluck('version')->unique()->count())->toBe($profile->versions()->count())
        ->and($draft->fresh()->instructions)->toBe('A saved draft.')
        ->and($promoted->fresh()->examples)->toHaveCount(1 + count((array) $profile->currentVersion->examples))
        // Both writers number the version under the profile's row lock (the unique key guards the rest).
        ->and(file_get_contents(app_path('Actions/Office/PromoteFeedbackToExample.php')))->toContain('lockForUpdate')
        ->and(file_get_contents(app_path('Actions/Office/SaveAgentProfileVersion.php')))->toContain('lockForUpdate');
});

it('promotes when the password arrives before the other deferred fields (one form, one request)', function () {
    $feedback = promotable();

    Livewire::actingAs(staffUser())->test(EducationReview::class)->call('startPromote', $feedback->id)
        ->set('password', 'password')
        ->set('kind', 'good')
        ->set('language', 'uz')
        ->set('client', 'Menga yorliq printer kerak.')
        ->set('anonymised', true)
        ->call('promote')
        ->assertHasNoErrors()
        ->assertSet('password', '');

    expect($feedback->fresh()->promoted_version_id)->not->toBeNull();
});
