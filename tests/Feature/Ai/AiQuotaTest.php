<?php

use App\Actions\Ai\GenerateFieldText;
use App\Enums\AiErrorCode;
use App\Enums\AiRequestStatus;
use App\Enums\Role;
use App\Models\AiRequest;
use App\Services\Ai\AiCost;
use App\Services\Ai\AiException;
use App\Services\Ai\AiQuota;
use App\Support\Ai\AiContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = staffUser(Role::ContentManager);
    fakeTextAssistant();
});

function generateFor($user): AiRequest
{
    return app(GenerateFieldText::class)->handle($user, 'product.meta_title', 'Write the name.', new AiContext(name: 'Zebra ZD421'));
}

function expectAiError(callable $callback, AiErrorCode $code): void
{
    try {
        $callback();
    } catch (AiException $exception) {
        expect($exception->errorCode)->toBe($code);

        return;
    }

    test()->fail("Expected AiException [{$code->value}].");
}

it('is off by default and refuses requests without a row', function () {
    freshSecrets();

    expectAiError(fn () => generateFor($this->user), AiErrorCode::Disabled);
    expect(AiRequest::count())->toBe(0);
});

it('refuses when the Anthropic key is missing', function () {
    enableAiAssist(withKey: false);

    expectAiError(fn () => generateFor($this->user), AiErrorCode::NotConfigured);
    expect(AiRequest::count())->toBe(0);
});

it('runs a request when enabled and logs it', function () {
    enableAiAssist();

    $request = generateFor($this->user)->fresh();

    expect($request->status)->toBe(AiRequestStatus::Succeeded)
        ->and($request->user_id)->toBe($this->user->id)
        ->and($request->prompt)->toBe('Write the name.')
        ->and($request->response['ru'])->toBe('Принтер этикеток Zebra ZD421')
        ->and($request->input_tokens)->toBe(1000)
        ->and($request->output_tokens)->toBe(500)
        ->and($request->model)->toBe('claude-opus-5-5')
        // Opus: 1000 × 4 + 500 × 20 micro-USD.
        ->and($request->cost_micro_usd)->toBe(14_000);
});

it('limits text requests per user per Tashkent day', function () {
    enableAiAssist(['ai.daily_text_limit' => 2]);
    $other = staffUser(Role::ContentManager);

    generateFor($this->user);
    generateFor($this->user);

    expectAiError(fn () => generateFor($this->user), AiErrorCode::QuotaExceeded);

    // The refusal is logged as rejected and doesn't count; other users are unaffected.
    expect(AiRequest::query()->where('status', AiRequestStatus::Rejected)->count())->toBe(1)
        ->and(AiQuota::usedToday($this->user))->toBe(2)
        ->and(generateFor($other)->status)->not->toBe(AiRequestStatus::Rejected);
});

it('starts a new day at midnight in Tashkent, not UTC', function () {
    enableAiAssist(['ai.daily_text_limit' => 1]);

    // 23:30 Tashkent on the 9th = 18:30 UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-09 18:30', 'UTC'));
    AiRequest::factory()->for($this->user)->create(['created_at' => now()]);

    expectAiError(fn () => generateFor($this->user), AiErrorCode::QuotaExceeded);

    // 00:10 Tashkent on the 10th = 19:10 UTC on the 9th.
    Carbon::setTestNow(Carbon::parse('2026-10-09 19:10', 'UTC'));

    expect(AiQuota::remainingToday($this->user))->toBe(1)
        ->and(generateFor($this->user)->fresh()->status)->toBe(AiRequestStatus::Succeeded);
});

it('stops everyone when the next request could pass the monthly budget', function () {
    enableAiAssist(['ai.monthly_budget_usd' => 5]);

    // Worst case of one Opus request: 6000 × 4 + 8192 × 20 = 187 840 micro-USD.
    expect(AiQuota::estimateMicroUsd('claude-opus-5-5'))->toBe(187_840);

    AiRequest::factory()->costing(4_800_000)->create();
    generateFor($this->user);

    // 4.80 + 0.014 spent + 0.188 estimate > 5 USD.
    expectAiError(fn () => generateFor(staffUser(Role::Admin)), AiErrorCode::BudgetExceeded);
    expect(AiQuota::budgetReached())->toBeFalse();
});

it('reserves open requests at the worst-case estimate', function () {
    enableAiAssist(['ai.monthly_budget_usd' => 1]);

    // Five queued requests reserve 5 × 0.188 = 0.94 USD before they ran; one more would pass 1 USD.
    AiRequest::factory()->count(5)->pending()->create(['model' => 'claude-opus-5-5']);

    expect(AiQuota::monthCostMicroUsd())->toBe(5 * 187_840);
    expectAiError(fn () => generateFor($this->user), AiErrorCode::BudgetExceeded);
});

it('checks the quota under a lock and refuses while another check holds it', function () {
    enableAiAssist();
    config(['ai-assist.lock_wait' => 0]);

    $lock = Cache::lock(AiQuota::LOCK, 10);
    $lock->get();

    expectAiError(fn () => generateFor($this->user), AiErrorCode::RateLimited);
    expect(AiRequest::count())->toBe(0);

    $lock->release();
    expect(generateFor($this->user)->status)->not->toBe(AiRequestStatus::Rejected);
});

it('computes the cost per model', function (string $model, int $input, int $output, int $micro) {
    expect(AiCost::microUsd($model, $input, $output))->toBe($micro);
})->with([
    'opus' => ['claude-opus-5-5', 1000, 500, 14_000],
    'sonnet' => ['claude-sonnet-5-5', 1000, 500, 7_000],
    'haiku' => ['claude-haiku-5-5', 1000, 500, 350],
    'haiku fractions' => ['claude-haiku-5-5', 7, 3, 2],
    'haiku one token' => ['claude-haiku-5-5', 1, 0, 0],
    'unknown model uses Opus rates' => ['claude-next', 1000, 500, 14_000],
]);

it('ignores last month in the budget', function () {
    enableAiAssist(['ai.monthly_budget_usd' => 1]);

    AiRequest::factory()->costing(5_000_000)->createdAt(now('Asia/Tashkent')->startOfMonth()->subMinute()->utc())->create();

    expect(AiQuota::monthCostMicroUsd())->toBe(0)
        ->and(generateFor($this->user)->status)->not->toBe(AiRequestStatus::Rejected);
});

it('refuses users without ai.use', function (Role $role) {
    enableAiAssist();

    generateFor(staffUser($role));
})->with([
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
])->throws(AuthorizationException::class);

it('rejects an unknown field', function () {
    enableAiAssist();

    app(GenerateFieldText::class)->handle($this->user, 'customer.phone', 'x', new AiContext);
})->throws(ValidationException::class);
