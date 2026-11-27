<?php

use App\Actions\Office\CallOfficeModel;
use App\Actions\Office\RunEducationDryRun;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\Role;
use App\Livewire\Admin\Office\EducationDryRun;
use App\Models\Activity;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\ChatAiUsageDaily;
use App\Models\Lead;
use App\Models\OfficeDraftFeedback;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupThread;
use App\Models\User;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';
require_once __DIR__.'/../OfficeAnalysisTest.php';

/*
| Plan 46 T7: the dry-run test console (ED-4, ED46-6, ED46-7). The fake ChatModel only; no network call.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A saved, unpublished version B of a role.
 *
 * @param  array<string, mixed>  $attributes
 */
function dryVersionB(AgentRole $role = AgentRole::Sales, array $attributes = []): AgentProfileVersion
{
    $profile = AgentProfile::forRole($role);

    return $profile->versions()->create(array_merge([
        'version' => (int) $profile->versions()->max('version') + 1, 'instructions' => 'Version B instructions.', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 800,
    ], $attributes));
}

/**
 * Counts of everything a dry run must not write.
 *
 * @return array<string, int>
 */
function dryWrites(): array
{
    return [
        'analyses' => BusinessChatAnalysis::query()->count(), 'group_analyses' => TelegramGroupAnalysis::query()->count(), 'threads' => TelegramGroupThread::query()->count(),
        'leads' => Lead::query()->count(), 'activities' => Activity::query()->count(), 'feedback' => OfficeDraftFeedback::query()->count(), 'versions' => AgentProfileVersion::query()->count(),
    ];
}

/**
 * A client chat, an Admin and a version B; returns [chat, admin, b].
 *
 * @return array{chat: BusinessChat, admin: User, b: AgentProfileVersion}
 */
function drySetup(?string $clientText = null): array
{
    $chat = officeDueChat([], $clientText);
    BusinessChat::query()->whereKey($chat->id)->update(['analysis_due_at' => null]);

    return ['chat' => $chat, 'admin' => staffUser(), 'b' => dryVersionB()];
}

function dryPage(User $admin, AgentRole $role = AgentRole::Sales)
{
    return Livewire::actingAs($admin)->test(EducationDryRun::class, ['role' => $role->value]);
}

it('runs the three steps (router, agent A, agent B) side by side and writes nothing but counters', function () {
    $s = drySetup();
    $before = dryWrites();
    Queue::fake();
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(['summary' => 'Summary A']), officeAgentAnswer(['summary' => 'Summary B', 'escalation' => 'none']));

    $page = dryPage($s['admin'])->set('sourceType', 'chat')->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)
        ->call('start')->assertHasNoErrors()->assertSeeHtml('data-step="1"')
        ->call('runStep', 2)->call('runStep', 3)->assertHasNoErrors()
        ->assertSee('Summary A')->assertSee('Summary B')->assertSeeHtml('data-step="3"');

    expect($fake->calls())->toBe(3)
        ->and($fake->requests[0]->model)->toBe('claude-haiku-5-5')
        ->and($fake->requests[1]->timeout)->toBeLessThanOrEqual(25.0)
        ->and($fake->requests[1]->system[2]['text'])->toContain('You help a Poson sales manager')
        ->and($fake->requests[2]->system[2]['text'])->toContain('Version B instructions.')
        ->and(dryWrites())->toBe($before)
        ->and(ChatAiUsageDaily::query()->where('channel', AiUsageChannel::OfficeDry->value)->value('requests'))->toBe(3)
        ->and(ChatAiUsageDaily::query()->whereIn('channel', ['vo_route', 'vo_sales'])->count())->toBe(0);

    Queue::assertNothingPushed();
    expect($page->get('runId'))->not->toBeNull();
});

it('compares router A and router B for the router role (two steps)', function () {
    $s = drySetup();
    $b = dryVersionB(AgentRole::Router, ['instructions' => 'Router B.', 'model' => 'claude-haiku-5-5', 'max_tokens' => 300]);
    $fake = FakeChatModel::bind(officeRouterAnswer(['topic' => 'Topic A']), officeRouterAnswer(['topic' => 'Topic B']));

    dryPage($s['admin'], AgentRole::Router)->set('sourceId', $s['chat']->id)->set('versionB', $b->id)->call('start')->call('runStep', 2)
        ->assertSee('Topic A')->assertSee('Topic B')->call('runStep', 3)->assertHasErrors('dry');

    expect($fake->calls())->toBe(2);
});

it('runs on a tracked group with the first thread of the role, no thread when the router found none', function () {
    $setup = groupForAnalysis();
    $b = dryVersionB();
    $admin = staffUser();
    $before = dryWrites();
    $fake = groupStandardModel();

    dryPage($admin)->set('sourceType', 'group')->set('sourceId', $setup['group']->id)->set('versionB', $b->id)
        ->call('start')->call('runStep', 2)->call('runStep', 3)->assertHasNoErrors()->assertSeeHtml('data-step="3"');

    expect($fake->requests)->toHaveCount(3)
        ->and($fake->requests[1]->messages[0]['content'])->toContain('<thread>')
        ->and(dryWrites())->toBe($before);
});

it('refuses a non-admin with 403, on the page and on the action', function (Role $role) {
    $s = drySetup();
    $user = staffUser($role);
    FakeChatModel::bind(officeRouterAnswer());

    Livewire::actingAs($user)->test(EducationDryRun::class, ['role' => 'sales'])->assertForbidden();
    expect(fn () => app(RunEducationDryRun::class)->start($user, AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id))->toThrow(AuthorizationException::class);
})->with([Role::SalesManager, Role::ServiceEngineer, Role::ContentManager]);

it('is refused while the office AI is off, with no call', function () {
    $s = drySetup();
    officeSettings(['ai_enabled' => false]);
    $fake = FakeChatModel::bind(officeRouterAnswer());

    dryPage($s['admin'])->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)->call('start')->assertHasErrors('dry')->assertSeeHtml('data-ai-off');

    expect($fake->calls())->toBe(0);
});

it('stops as capped without a call when the dry cap is reached', function () {
    $s = drySetup();
    officeSettings(['caps' => ['vo_dry' => ['requests' => 0, 'tokens' => 300000]]]);
    $fake = FakeChatModel::bind(officeRouterAnswer());

    dryPage($s['admin'])->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)->call('start')->assertSeeHtml('data-outcome="capped"');

    expect($fake->calls())->toBe(0);
});

it('refuses a step without step 1, a version of another agent and a source that is not a client chat', function () {
    $s = drySetup();
    $admin = $s['admin'];
    $run = app(RunEducationDryRun::class);
    Cache::put('office-dry:manual', ['user' => $admin->id, 'role' => 'sales', 'source_type' => 'chat', 'source_id' => $s['chat']->id, 'a' => 1, 'b' => $s['b']->id, 'steps' => [], 'plan' => null], now()->addMinutes(30));
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());

    expect(fn () => $run->step($admin, 'manual', 2))->toThrow(ValidationException::class)
        ->and(fn () => $run->step($admin, 'manual', 3))->toThrow(ValidationException::class)
        ->and(fn () => $run->step($admin, 'manual', 9))->toThrow(ValidationException::class)
        ->and(fn () => $run->start($admin, AgentRole::Sales, 'chat', $s['chat']->id, dryVersionB(AgentRole::Service)->id))->toThrow(ValidationException::class)
        ->and(fn () => $run->start($admin, AgentRole::Sales, 'chat', BusinessChat::factory()->create()->id, $s['b']->id))->toThrow(ValidationException::class)
        ->and($fake->calls())->toBe(0);
});

it('has a step in flight refuse the next one', function () {
    $s = drySetup();
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());
    $started = app(RunEducationDryRun::class)->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);
    $lock = Cache::lock('office-dry-flight:'.$started['run'], 60);
    $lock->get();

    expect(fn () => app(RunEducationDryRun::class)->step($s['admin'], $started['run'], 2))->toThrow(ValidationException::class, __('office.dry.errors.busy'));

    $lock->release();
    app(RunEducationDryRun::class)->step($s['admin'], $started['run'], 2);

    expect($fake->calls())->toBe(2);
});

it('shows the usage and links the console from Education', function () {
    $this->actingAs(staffUser())->get(route('admin.office.education.test', 'sales'))->assertOk()->assertSee(__('office.dry.title'));
    $this->actingAs(staffUser())->get(route('admin.office.education', 'sales'))->assertSee('data-test-console-link', false);
});

it('has the dry cap row in Office settings and keeps it when a caller does not send it', function () {
    expect(AiUsageChannel::office())->toContain(AiUsageChannel::OfficeDry)
        ->and(OfficeSettings::caps(AiUsageChannel::OfficeDry))->toBe(['requests' => 30, 'tokens' => 300000])
        ->and(OfficeSettings::maxCaps(AiUsageChannel::OfficeDry))->toBe(['requests' => 150, 'tokens' => 1500000])
        ->and(CallOfficeModel::CHANNELS)->toContain(AiUsageChannel::OfficeDry);
});

it('arch: only the dry-run action passes OfficeDry to the office caller, and it writes nothing else', function () {
    $uses = collect(File::allFiles(app_path()))->filter(fn ($file) => str_contains($file->getContents(), 'OfficeDry'))->map(fn ($file) => $file->getRelativePathname())->sort()->values()->all();

    // The channel is named by the allow-list of the caller, the settings (a cap row), the console's usage line and the enum; only the action calls the caller with it.
    expect($uses)->toBe(['Actions/Office/CallOfficeModel.php', 'Actions/Office/RunEducationDryRun.php', 'Actions/Office/SaveOfficeSettings.php', 'Enums/AiUsageChannel.php', 'Livewire/Admin/Office/EducationDryRun.php']);

    $callers = collect(File::allFiles(app_path()))->filter(fn ($file) => preg_match('/(modelCall|call)->handle\(/', $file->getContents()) === 1 && str_contains($file->getContents(), 'AiUsageChannel::OfficeDry'))->map(fn ($file) => $file->getRelativePathname())->values()->all();

    expect($callers)->toBe(['Actions/Office/RunEducationDryRun.php']);

    $source = file_get_contents(app_path('Actions/Office/RunEducationDryRun.php'));

    foreach (['SyncLeadFrom', '->save(', '::create(', '->create(', 'updateOrCreate', 'forceFill', 'Activity', 'Lead::', 'TelegramClient', 'Http::', 'dispatch(', 'DB::'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    foreach (['RunBusinessChatAnalysis', 'RunGroupAnalysis', 'CallOfficeModel'] as $file) {
        expect(file_get_contents(app_path("Actions/Office/{$file}.php")))->not->toContain('OfficeDry::');
    }
});
