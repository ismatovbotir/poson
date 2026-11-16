<?php

use App\Actions\Catalog\Ai\PruneAiDrafts;
use App\Console\Commands\PruneAiDrafts as PruneAiDraftsCommand;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Models\AiProductDraft;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/AiWizardHelpers.php';

/**
 * Plan 32 PW-28, PW-18: the hourly temp cleanup. Expires drafts past their
 * TTL, fails lost stages, deletes vision copies after an hour, the folder of
 * an expired draft, orphan folders and rows after 7 days, and never touches
 * a draft that is being applied.
 */
beforeEach(function () {
    wizStorage();
    $this->user = staffUser(Role::ContentManager);
    $this->prune = app(PruneAiDrafts::class);
});

/**
 * Write a file in a draft folder and age it by $minutes.
 */
function wizAgedFile(string $uuid, string $relative, int $minutes = 0): string
{
    $path = Storage::disk('local')->path(AiProductDraft::folder($uuid).'/'.$relative);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, 'x');
    touch($path, now()->subMinutes($minutes)->getTimestamp());
    touch(dirname($path), now()->subMinutes($minutes)->getTimestamp());

    return $relative;
}

function wizHasFile(string $uuid, string $relative): bool
{
    return Storage::disk('local')->exists(AiProductDraft::folder($uuid).'/'.$relative);
}

function wizAge(AiProductDraft $draft, array $columns): void
{
    AiProductDraft::query()->whereKey($draft->id)->toBase()->update($columns);
}

describe('expiry and stale states', function () {
    it('expires open drafts past their TTL and leaves live, applying and applied ones', function () {
        $expired = AiProductDraft::factory()->forUser($this->user)->expired()->create();
        $live = AiProductDraft::factory()->forUser($this->user)->create();
        $applying = AiProductDraft::factory()->forUser($this->user)->expired()->status(AiDraftStatus::Applying)->create();
        $applied = AiProductDraft::factory()->forUser($this->user)->expired()->status(AiDraftStatus::Applied)->create();
        $ready = AiProductDraft::factory()->forUser($this->user)->expired()->status(AiDraftStatus::Ready)->create();

        $counts = $this->prune->handle();

        expect($counts['expired'])->toBe(2)
            ->and($expired->refresh()->status)->toBe(AiDraftStatus::Expired)
            ->and($ready->refresh()->status)->toBe(AiDraftStatus::Expired)
            ->and($live->refresh()->status)->toBe(AiDraftStatus::Open)
            ->and($applying->refresh()->status)->toBe(AiDraftStatus::Applying)
            ->and($applied->refresh()->status)->toBe(AiDraftStatus::Applied);
    });

    it('moves a lost stage (no live job any more) to failed with the error key', function (AiDraftStatus $status) {
        $lost = AiProductDraft::factory()->forUser($this->user)->status($status)->create();
        $fresh = AiProductDraft::factory()->forUser($this->user)->status($status)->create();
        wizAge($lost, ['updated_at' => now()->subHours(3)]);

        $counts = $this->prune->handle();

        expect($counts['stale'])->toBe(1)
            ->and($lost->refresh()->status)->toBe(AiDraftStatus::Failed)->and($lost->error)->toBe('error')
            ->and($fresh->refresh()->status)->toBe($status);
    })->with([AiDraftStatus::Seeing, AiDraftStatus::Writing]);

    it('fails a draft stuck in applying only after the applying timeout', function () {
        $stuck = AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Applying)->create();
        $running = AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Applying)->create();
        wizAge($stuck, ['updated_at' => now()->subHours(5)]);
        wizAge($running, ['updated_at' => now()->subMinutes(10)]);

        $this->prune->handle();

        expect($stuck->refresh()->status)->toBe(AiDraftStatus::Failed)->and($running->refresh()->status)->toBe(AiDraftStatus::Applying);
    });
});

describe('files', function () {
    it('deletes vision copies after an hour but not the cleaned images of a live draft', function () {
        $draft = AiProductDraft::factory()->forUser($this->user)->create();
        wizAgedFile($draft->uuid, 'vision/1.jpg', 90);
        wizAgedFile($draft->uuid, 'clean/u1-clean.jpg', 90);

        $counts = $this->prune->handle();

        expect($counts['vision'])->toBe(1)->and(wizHasFile($draft->uuid, 'vision/1.jpg'))->toBeFalse()->and(wizHasFile($draft->uuid, 'clean/u1-clean.jpg'))->toBeTrue();
    });

    it('keeps vision copies younger than an hour', function () {
        $draft = AiProductDraft::factory()->forUser($this->user)->create();
        wizAgedFile($draft->uuid, 'vision/1.jpg', 10);

        $this->prune->handle();

        expect(wizHasFile($draft->uuid, 'vision/1.jpg'))->toBeTrue();
    });

    it('deletes the whole folder of an expired draft, kept originals included', function () {
        $draft = AiProductDraft::factory()->forUser($this->user)->expired()->create();
        wizAgedFile($draft->uuid, 'clean/u1-clean.jpg');
        wizAgedFile($draft->uuid, 'kept/m5.jpg');
        wizAgedFile($draft->uuid, 'vision/1.jpg');

        $counts = $this->prune->handle();

        expect($counts['folders'])->toBe(1)->and(Storage::disk('local')->exists(AiProductDraft::folder($draft->uuid)))->toBeFalse();
    });

    it('keeps the files of a draft being applied even past its TTL', function () {
        $draft = AiProductDraft::factory()->forUser($this->user)->expired()->status(AiDraftStatus::Applying)->create();
        wizAgedFile($draft->uuid, 'clean/u1-clean.jpg');

        $this->prune->handle();

        expect(wizHasFile($draft->uuid, 'clean/u1-clean.jpg'))->toBeTrue();
    });

    it('deletes orphan folders without a draft row after an hour and folders with other names at once', function () {
        $old = (string) Str::uuid();
        $young = (string) Str::uuid();
        wizAgedFile($old, 'clean/u1-clean.jpg', 120);
        wizAgedFile($young, 'clean/u1-clean.jpg', 5);
        Storage::disk('local')->put('ai-drafts/not-a-uuid/file.jpg', 'x');

        $counts = $this->prune->handle();

        expect($counts['orphans'])->toBe(2)
            ->and(wizHasFile($old, 'clean/u1-clean.jpg'))->toBeFalse()
            ->and(wizHasFile($young, 'clean/u1-clean.jpg'))->toBeTrue()
            ->and(Storage::disk('local')->exists('ai-drafts/not-a-uuid'))->toBeFalse();
    });

    it('never touches files outside ai-drafts', function () {
        Storage::disk('local')->put('private/keep.txt', 'x');
        Storage::disk('local')->put('originals/1/photo.jpg', 'x');

        $this->prune->handle();

        expect(Storage::disk('local')->exists('private/keep.txt'))->toBeTrue()->and(Storage::disk('local')->exists('originals/1/photo.jpg'))->toBeTrue();
    });
});

describe('rows', function () {
    it('deletes rows older than seven days with their folders, not the newer ones nor a draft being applied', function () {
        $old = AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Applied)->create();
        $recent = AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Applied)->create();
        $applying = AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Applying)->create();
        wizAge($old, ['created_at' => now()->subDays(8)]);
        wizAge($applying, ['created_at' => now()->subDays(8)]);
        wizAgedFile($old->uuid, 'kept/m1.jpg');

        $counts = $this->prune->handle();

        expect($counts['rows'])->toBe(1)
            ->and(AiProductDraft::query()->whereKey($old->id)->exists())->toBeFalse()
            ->and(AiProductDraft::query()->whereKey($recent->id)->exists())->toBeTrue()
            ->and(AiProductDraft::query()->whereKey($applying->id)->exists())->toBeTrue()
            ->and(wizHasFile($old->uuid, 'kept/m1.jpg'))->toBeFalse();
    });

    it('is idempotent: a second run changes nothing', function () {
        AiProductDraft::factory()->forUser($this->user)->expired()->create();

        $this->prune->handle();

        expect(array_sum($this->prune->handle()))->toBe(0);
    });
});

describe('command and schedule', function () {
    it('runs from artisan and prints the counts', function () {
        AiProductDraft::factory()->forUser($this->user)->expired()->create();

        $this->artisan('ai:prune-drafts')->assertSuccessful()->expectsOutputToContain('expired');

        expect(AiProductDraft::query()->sole()->status)->toBe(AiDraftStatus::Expired);
    });

    it('has an English description and help text', function () {
        $command = Artisan::all()['ai:prune-drafts'];

        expect($command)->toBeInstanceOf(PruneAiDraftsCommand::class)
            ->and($command->getDescription())->toBeString()->not->toBe('')->toMatch('/^[\x20-\x7E]+$/')
            ->and($command->getHelp())->toContain('every hour')->toContain('Scheduled hourly');
    });

    it('is scheduled hourly, on one server, without overlapping', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains($event->command, 'ai:prune-drafts'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->onOneServer)->toBeTrue();
    });
});
