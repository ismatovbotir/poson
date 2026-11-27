<?php

use App\Actions\Office\RunEducationDryRun;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Models\ChatAiUsageDaily;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../Office/Education/DryRunTest.php';

/*
| Plan 46 §9, abuse cases 9 to 13 (ED46-6, ED46-7): replay, forged run, run limit, parallel reserve, no leaks.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

it('abuse 9: replaying the step-2 snapshot twice gives one model call', function () {
    $s = drySetup();
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(), officeAgentAnswer(), officeAgentAnswer());

    $page = dryPage($s['admin'])->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)->call('start');
    $page->call('runStep', 2)->assertHasNoErrors();
    $page->call('runStep', 2)->assertHasErrors('dry');
    $page->call('runStep', 2)->assertHasErrors('dry');

    expect($fake->calls())->toBe(2);

    // The same through a second component with the same run id is the same record: still refused.
    expect(fn () => app(RunEducationDryRun::class)->step($s['admin'], $page->get('runId'), 2))->toThrow(ValidationException::class)
        ->and($fake->calls())->toBe(2);
});

it('abuse 10: a forged, expired or foreign run id is refused and the run id cannot be set from the browser', function () {
    $s = drySetup();
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer());
    $run = app(RunEducationDryRun::class);
    $started = $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);

    expect(fn () => $run->step($s['admin'], 'forged-uuid', 2))->toThrow(ValidationException::class)
        ->and(fn () => $run->step($s['admin'], '', 2))->toThrow(ValidationException::class)
        ->and(fn () => $run->step(staffUser(), $started['run'], 2))->toThrow(ValidationException::class);

    Cache::forget('office-dry:'.$started['run']);

    expect(fn () => $run->step($s['admin'], $started['run'], 2))->toThrow(ValidationException::class)
        ->and($fake->calls())->toBe(1);

    expect(fn () => dryPage($s['admin'])->set('runId', 'forged'))->toThrow(Exception::class, 'Cannot update locked property');
});

it('abuse 11: the 4th run within the hour is refused, the global run limit holds too', function () {
    $s = drySetup();
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeRouterAnswer(), officeRouterAnswer(), officeRouterAnswer());
    $run = app(RunEducationDryRun::class);

    foreach (range(1, 3) as $_) {
        $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);
    }

    expect(fn () => $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id))->toThrow(ValidationException::class, __('office.dry.errors.throttled'))
        ->and($fake->calls())->toBe(3);

    // Another Admin has their own 3, up to the global 6.
    $other = staffUser();
    FakeChatModel::bind(...array_fill(0, 4, officeRouterAnswer()));
    foreach (range(1, 3) as $_) {
        $run->start($other, AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);
    }

    $third = staffUser();

    expect(fn () => $run->start($third, AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id))->toThrow(ValidationException::class, __('office.dry.errors.throttled'));
});

it('abuse 11: the global call limit stops steps 2 and 3 being replayed alone', function () {
    $s = drySetup();
    config(['office.education.dry_calls_global_per_hour' => 2]);
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(), officeAgentAnswer());
    $run = app(RunEducationDryRun::class);
    $started = $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);
    $run->step($s['admin'], $started['run'], 2);

    expect(fn () => $run->step($s['admin'], $started['run'], 3))->toThrow(ValidationException::class, __('office.dry.errors.throttled'))
        ->and($fake->calls())->toBe(2);
});

it('abuse 12: two step-1 calls with room for one reservation leave exactly one', function () {
    $s = drySetup();
    officeSettings(['caps' => ['vo_dry' => ['requests' => 1, 'tokens' => 300000]]]);
    $fake = FakeChatModel::bind(officeRouterAnswer(), officeRouterAnswer());
    $run = app(RunEducationDryRun::class);

    $first = $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);
    $second = $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);

    expect($first['result']['outcome'])->toBe('ok')
        ->and($second['result']['outcome'])->toBe('capped')
        ->and($fake->calls())->toBe(1)
        ->and(ChatAiUsageDaily::query()->where('channel', AiUsageChannel::OfficeDry->value)->value('requests'))->toBe(1)
        ->and(ChatAiUsageDaily::query()->where('channel', AiUsageChannel::OfficeDry->value)->value('reserved_tokens'))->toBe(0);
});

it('abuse 13: the page and the snapshot hold no transcript and no blocked text', function () {
    $s = drySetup('TRANSCRIPT-MARKER-77 yorliq printer kerak');
    FakeChatModel::bind(officeRouterAnswer(['summary' => 'Neutral summary.']), officeAgentAnswer(['draft_reply' => 'BLOCKED-DRAFT-TEXT: 15% discount today!']), officeAgentAnswer(['draft_reply' => 'Hello, which label size do you need?']));

    $page = dryPage($s['admin'])->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)->call('start')->call('runStep', 2)->call('runStep', 3);
    $html = $page->html();
    $snapshot = json_encode($page->snapshot ?? $page->lastState->getSnapshot());

    expect($html)->not->toContain('TRANSCRIPT-MARKER-77')->not->toContain('BLOCKED-DRAFT-TEXT')->not->toContain('discount')
        ->and($html)->toContain('which label size')->toContain(__('office.show.draft_blocked'))
        ->and($snapshot)->not->toContain('TRANSCRIPT-MARKER-77')->not->toContain('BLOCKED-DRAFT-TEXT')->not->toContain('which label size')->not->toContain('Neutral summary')
        ->and(json_encode(Cache::get('office-dry:'.$page->get('runId'))))->not->toContain('TRANSCRIPT-MARKER-77')->not->toContain('BLOCKED-DRAFT-TEXT');
});

it('keeps a rejected draft out of the page: a no_draft escalation shows only the reason', function () {
    $s = drySetup('chegirma bering, yorliq printer kerak');
    FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(['draft_reply' => 'NEGOTIATED-REPLY ok']), officeAgentAnswer());

    $html = dryPage($s['admin'])->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)->call('start')->call('runStep', 2)->html();

    expect($html)->not->toContain('NEGOTIATED-REPLY')->toContain('data-dry-hold');
});

it('abuse 11: many attempts in a row produce at most 3 runs and the refused ones give their count back', function () {
    $s = drySetup();
    FakeChatModel::bind(...array_fill(0, 12, officeRouterAnswer()));
    $run = app(RunEducationDryRun::class);
    $started = 0;

    foreach (range(1, 10) as $_) {
        try {
            $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);
            $started++;
        } catch (ValidationException) {
        }
    }

    expect($started)->toBe(3)
        ->and(RateLimiter::attempts('office-dry-runs:'.$s['admin']->id))->toBe(3)
        ->and(RateLimiter::attempts('office-dry-runs'))->toBe(3);
});

it('a step 1 that is refused gives the run back and stores no record', function () {
    $s = drySetup();
    config(['office.education.dry_calls_global_per_hour' => 0]);
    FakeChatModel::bind(officeRouterAnswer());
    $run = app(RunEducationDryRun::class);

    expect(fn () => $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id))->toThrow(ValidationException::class, __('office.dry.errors.throttled'))
        ->and(RateLimiter::attempts('office-dry-runs:'.$s['admin']->id))->toBe(0)
        ->and(RateLimiter::attempts('office-dry-runs'))->toBe(0);

    config(['office.education.dry_calls_global_per_hour' => 18]);
    officeSettings(['ai_enabled' => false]);

    expect(fn () => $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id))->toThrow(ValidationException::class)
        ->and(RateLimiter::attempts('office-dry-runs:'.$s['admin']->id))->toBe(0);
});

it('a step that throws frees its slot so it can be retried, and the next-step button waits for an ok step', function () {
    $s = drySetup();
    $fake = FakeChatModel::bind(officeRouterAnswer(), new RuntimeException('boom'), officeAgentAnswer());
    $run = app(RunEducationDryRun::class);
    $started = $run->start($s['admin'], AgentRole::Sales, 'chat', $s['chat']->id, $s['b']->id);

    expect(fn () => $run->step($s['admin'], $started['run'], 2))->toThrow(RuntimeException::class)
        ->and($run->step($s['admin'], $started['run'], 2)['outcome'])->toBe('ok')
        ->and($fake->calls())->toBe(3);

    officeSettings(['caps' => ['vo_dry' => ['requests' => 0, 'tokens' => 300000]]]);
    $page = dryPage($s['admin'])->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)->call('start');

    $page->assertSeeHtml('data-outcome="capped"')->assertDontSeeHtml('data-action="step-2"');
    expect(fn () => $run->step($s['admin'], $page->get('runId'), 2))->toThrow(ValidationException::class);
});

it('uses literal Tailwind classes in the console grid', function () {
    $view = file_get_contents(resource_path('views/livewire/admin/office/education-dry-run.blade.php'));

    expect($view)->not->toContain('grid-cols-{{')->toContain("'lg:grid-cols-3'");
});

it('keeps the results readable after the last step', function () {
    $s = drySetup();
    FakeChatModel::bind(officeRouterAnswer(), officeAgentAnswer(['summary' => 'Readable summary']), officeAgentAnswer());

    $page = dryPage($s['admin'])->set('sourceId', $s['chat']->id)->set('versionB', $s['b']->id)->call('start')->call('runStep', 2)->call('runStep', 3);

    expect(Cache::get('office-dry:'.$page->get('runId')))->not->toBeNull();
    $page->assertSee('Readable summary')->call('runStep', 3)->assertHasErrors('dry');
});
