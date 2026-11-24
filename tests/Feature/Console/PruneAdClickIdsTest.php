<?php

use App\Console\Commands\PruneAdClickIds;
use App\Models\Lead;
use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Plan 40 (YD-8): click ids are cleared after 12 months, rows are kept.
 */
it('nulls yclid and ym_client_id on leads and orders older than 12 months and keeps newer ones', function () {
    $oldLead = Lead::factory()->fromDirect()->create(['created_at' => now()->subMonths(12)->subDay()]);
    $newLead = Lead::factory()->fromDirect()->create(['created_at' => now()->subMonths(11)]);
    $oldOrder = Order::factory()->fromDirect()->create(['created_at' => now()->subMonths(13)]);
    $newOrder = Order::factory()->fromDirect()->create(['created_at' => now()->subDays(3)]);
    $updatedAt = $oldLead->fresh()->updated_at;

    $this->artisan('analytics:prune-click-ids')
        ->expectsOutputToContain('leads')
        ->assertSuccessful();

    expect($oldLead->fresh()->only(['yclid', 'ym_client_id']))->toBe(['yclid' => null, 'ym_client_id' => null])
        ->and($oldLead->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($oldOrder->fresh()->only(['yclid', 'ym_client_id']))->toBe(['yclid' => null, 'ym_client_id' => null])
        ->and($newLead->fresh()->yclid)->not->toBeNull()
        ->and($newOrder->fresh()->ym_client_id)->not->toBeNull()
        ->and(Lead::query()->count())->toBe(2)
        ->and(Order::query()->count())->toBe(2);

    // Idempotent.
    $this->artisan('analytics:prune-click-ids')->assertSuccessful();
    expect($newLead->fresh()->yclid)->not->toBeNull();
});

it('is scheduled nightly and documents itself', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'analytics:prune-click-ids'));
    $command = app(PruneAdClickIds::class);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 1 * * *')
        ->and($command->getDescription())->not->toBe('')
        ->and($command->getHelp())->toContain('Scheduled nightly');
});
