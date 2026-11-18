<?php

use App\Actions\Repairs\CreateRepair;
use App\Actions\Repairs\EnsureRepairStatusToken;
use App\Actions\Repairs\RotateRepairStatusToken;
use App\Data\Repairs\RepairData;
use App\Enums\ActivityType;
use App\Enums\RepairDeviceType;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Plan 35 I1: the public status token (P35-1 … P35-5).
 */
it('issues a 40 character alphanumeric token, unique across many repairs', function () {
    $repairs = Repair::factory()->count(200)->create();

    foreach ($repairs as $repair) {
        app(EnsureRepairStatusToken::class)->handle($repair);
    }

    $tokens = Repair::query()->pluck('public_token');

    expect($tokens->unique())->toHaveCount(200)
        ->and($tokens->every(fn ($token) => preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1))->toBeTrue();
});

it('never serializes the token', function () {
    $repair = Repair::factory()->withStatusToken()->create();

    expect($repair->toArray())->not->toHaveKeys(['public_token', 'public_token_rotated_at'])
        ->and($repair->toJson())->not->toContain($repair->public_token)
        ->and($repair->fresh()->toArray())->not->toHaveKey('public_token')
        ->and($repair->fresh()->public_token)->toBe($repair->public_token)
        ->and((new Repair)->getFillable())->not->toContain('public_token')
        ->not->toContain('public_token_rotated_at');
});

it('sets a token when CreateRepair takes a device in', function () {
    $engineer = staffUser(Role::ServiceEngineer);

    $repair = app(CreateRepair::class)->handle(new RepairData(
        contactName: 'Ali Valiyev',
        contactPhone: '90 123 45 67',
        deviceType: RepairDeviceType::Scanner,
        intakeCondition: 'Scratched housing',
        problem: 'No reads',
    ), $engineer);

    expect($repair->public_token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($repair->fresh()->public_token)->toBe($repair->public_token);
});

it('ensures the token once and touches nothing else', function () {
    $this->travelTo(now()->startOfSecond());
    $repair = Repair::factory()->create();
    $changedAt = $repair->status_changed_at->toDateTimeString();
    $updatedAt = $repair->updated_at->toDateTimeString();

    $this->travel(2)->hours();
    $first = app(EnsureRepairStatusToken::class)->handle($repair);
    $second = app(EnsureRepairStatusToken::class)->handle(Repair::find($repair->id));

    $fresh = $repair->fresh();

    expect($second)->toBe($first)
        ->and($fresh->public_token)->toBe($first)
        ->and($fresh->status_changed_at->toDateTimeString())->toBe($changedAt)
        ->and($fresh->updated_at->toDateTimeString())->toBe($updatedAt);
});

it('knows when the status link is live', function (?string $state, bool $live) {
    $factory = Repair::factory();

    $repair = match ($state) {
        'open' => $factory->withStatusToken()->create(),
        'closed 29' => $factory->withStatusToken()->closedDaysAgo(29)->create(),
        'closed 31' => $factory->withStatusToken()->closedDaysAgo(31)->create(),
        default => $factory->create(),
    };

    expect($repair->isStatusLinkLive())->toBe($live);
})->with([
    'open' => ['open', true],
    'closed 29 days ago' => ['closed 29', true],
    'closed 31 days ago' => ['closed 31', false],
    'no token' => [null, false],
]);

it('rotates the token and logs it without the value', function () {
    $admin = staffUser(Role::Admin);
    $repair = Repair::factory()->withStatusToken()->closedDaysAgo(10)->create();
    $old = $repair->public_token;
    $closedAt = $repair->closed_at->toDateTimeString();

    app(RotateRepairStatusToken::class)->handle($repair, $admin);

    $fresh = $repair->fresh();
    $activity = Activity::query()->where('type', ActivityType::Repair->value)->latest('id')->firstOrFail();
    $dump = json_encode($activity->toArray());

    expect($fresh->public_token)->not->toBe($old)
        ->and($fresh->public_token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($fresh->public_token_rotated_at)->not->toBeNull()
        ->and($fresh->closed_at->toDateTimeString())->toBe($closedAt)
        ->and($activity->meta)->toBe(['action' => 'status_link_rotated'])
        ->and($dump)->not->toContain($old)->not->toContain($fresh->public_token);
});

it('allows five rotations a day, the sixth fails', function () {
    $admin = staffUser(Role::Admin);
    $repair = Repair::factory()->withStatusToken()->create();

    foreach (range(1, 5) as $i) {
        app(RotateRepairStatusToken::class)->handle($repair, $admin);
    }

    expect(fn () => app(RotateRepairStatusToken::class)->handle($repair, $admin))->toThrow(ValidationException::class);
});

it('refuses a rotation the policy denies', function () {
    $sales = staffUser(Role::SalesManager);
    $repair = Repair::factory()->withStatusToken()->create();

    expect(fn () => app(RotateRepairStatusToken::class)->handle($repair, $sales))
        ->toThrow(AuthorizationException::class);
});

it('decides rotateStatusLink and printReceipt per role and repair', function (string $who, bool $closed, bool $rotate, bool $print) {
    $admin = staffUser(Role::Admin);
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();

    $user = match ($who) {
        'admin' => $admin,
        'sales' => User::factory()->withRole(Role::SalesManager)->create(),
        'content' => User::factory()->withRole(Role::ContentManager)->create(),
        default => $engineer,
    };

    $factory = Repair::factory();
    $factory = match ($who) {
        'colleague' => $factory->assignedTo($colleague),
        'unassigned' => $factory,
        'assigned' => $factory->assignedTo($engineer),
        default => $factory->assignedTo($colleague),
    };
    $repair = ($closed ? $factory->closedDaysAgo(1) : $factory)->create();

    if ($who === 'colleague') {
        $user = $engineer;
    }

    expect($user->can('rotateStatusLink', $repair))->toBe($rotate, 'rotate')
        ->and($user->can('printReceipt', $repair))->toBe($print, 'print');
})->with([
    'admin open' => ['admin', false, true, true],
    'admin closed' => ['admin', true, true, true],
    'sales open' => ['sales', false, false, true],
    'sales closed' => ['sales', true, false, true],
    'content open' => ['content', false, false, false],
    'content closed' => ['content', true, false, false],
    'assigned engineer open' => ['assigned', false, true, true],
    'assigned engineer closed' => ['assigned', true, true, true],
    'colleague engineer open' => ['colleague', false, false, false],
    'colleague engineer closed' => ['colleague', true, false, false],
    'unassigned open (engineer)' => ['unassigned', false, true, true],
    'unassigned closed (engineer)' => ['unassigned', true, false, true],
]);

it('builds the status url on the APP_URL host, not the request host', function () {
    config(['app.url' => 'https://poson.example']);
    $repair = Repair::factory()->withStatusToken()->create();

    $this->get('http://evil.test/uz');

    expect($repair->statusUrl())->toBe('https://poson.example/r/'.$repair->public_token)
        ->and(Repair::factory()->make()->statusUrl())->toBeNull();
});
