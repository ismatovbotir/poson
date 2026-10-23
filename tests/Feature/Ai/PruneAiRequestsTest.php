<?php

use App\Enums\Role;
use App\Models\AiRequest;
use App\Support\Integrations\IntegrationRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Validator;

it('clears prompt and response texts after 90 days and keeps the counts', function () {
    $old = AiRequest::factory()->createdAt(now()->subDays(91))->create();
    $recent = AiRequest::factory()->createdAt(now()->subDays(89))->create();

    $this->artisan('ai:prune-requests')->expectsOutputToContain('1 AI request(s) cleared.')->assertSuccessful();

    $old->refresh();
    expect($old->prompt)->toBeNull()
        ->and($old->response)->toBeNull()
        ->and($old->pruned_at)->not->toBeNull()
        ->and($old->cost_micro_usd)->toBe(5400)
        ->and($recent->fresh()->prompt)->not->toBeNull();
});

it('is scheduled nightly', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event): bool => str_contains((string) $event->command, 'ai:prune-requests')))->toBeTrue();
});

it('gives ai.use to the content manager and the usage screen to admins only', function () {
    $content = staffUser(Role::ContentManager);

    expect($content->can('ai.use'))->toBeTrue()
        ->and($content->can('ai-requests.viewAny'))->toBeFalse()
        ->and(staffUser(Role::SalesManager)->can('ai.use'))->toBeFalse()
        ->and(staffUser(Role::ServiceEngineer)->can('ai.use'))->toBeFalse();
});

it('accepts an empty monthly budget (no cap) or at least 1 USD', function (mixed $value, bool $valid) {
    $rules = IntegrationRegistry::field('ai.monthly_budget_usd')->rules;

    expect(Validator::make(['value' => $value], ['value' => ['nullable', ...$rules]])->passes())->toBe($valid);
})->with([
    'empty' => [null, true],
    'zero' => [0, false],
    'one' => [1, true],
    'fifty' => [50, true],
]);
