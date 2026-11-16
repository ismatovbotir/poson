<?php

use App\Actions\Catalog\Ai\AiDraftState;
use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Actions\Catalog\Ai\StartAiDraftStage;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Jobs\RunAiDraftStage;
use App\Models\AiProductDraft;
use App\Models\Setting;
use App\Models\User;
use App\Support\Chat\ChatSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AiWizardHelpers.php';

/**
 * Plan 32 §7, PW-8, PW-9, PW-12, PW-29: the guards in front of a stage start,
 * in order, and the one conditional UPDATE that makes a double click start one
 * job only. The queue is faked: nothing runs, nothing is called.
 */
beforeEach(function () {
    wizStorage();
    wizOn();
    Queue::fake();
    $this->user = staffUser(Role::ContentManager);
    $this->start = app(StartAiDraftStage::class);
});

/**
 * An open create draft with $photos uploads cleaned and stored on the (fake) disk.
 */
function wizDraftWithPhotos(User $user, int $photos = 1): AiProductDraft
{
    $draft = wizDraft($user, AiDraftStatus::Open);

    for ($i = 0; $i < $photos; $i++) {
        app(PrepareAiDraftImages::class)->addUpload($draft->refresh(), UploadedFile::fake()->image("photo{$i}.jpg", 640, 480));
    }

    return $draft->refresh();
}

describe('starting a stage', function () {
    it('moves the draft to seeing, builds the vision copies and dispatches one job on the ai-admin queue after commit', function () {
        $draft = wizDraftWithPhotos($this->user, 2);

        $outcome = $this->start->handle($this->user, $draft, RunAiDraftStage::VISION);

        $draft->refresh();

        expect($outcome)->toBe('started')
            ->and($draft->status)->toBe(AiDraftStatus::Seeing)
            ->and($draft->input['vision_map'])->toBe([0, 1])
            ->and(Storage::disk('local')->exists(AiProductDraft::folder($draft->uuid).'/vision/2.jpg'))->toBeTrue();

        Queue::assertPushed(RunAiDraftStage::class, 1);
        Queue::assertPushedOn('ai-admin', RunAiDraftStage::class, fn (RunAiDraftStage $job): bool => $job->draftId === $draft->id && $job->stage === 'vision' && $job->afterCommit === true);
    });

    it('starts the write stage only from confirmed facts', function () {
        $draft = wizDraft($this->user, AiDraftStatus::FactsReady);

        expect($this->start->handle($this->user, $draft, RunAiDraftStage::WRITE))->toBe('state');
        Queue::assertNothingPushed();

        $draft->forceFill(['facts' => wizFacts(), 'result' => ['facts_confirmed' => true]])->save();

        expect($this->start->handle($this->user, $draft, RunAiDraftStage::WRITE))->toBe('started')
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Writing);
        Queue::assertPushed(RunAiDraftStage::class, fn (RunAiDraftStage $job): bool => $job->stage === 'write');
    });

    it('lets a failed, refused or capped stage be retried, and each retry counts against the limiter', function (AiDraftStatus $status) {
        config(['chatbot.ai.product.limits.vision_per_hour_user' => 2]);
        $draft = wizDraftWithPhotos($this->user);
        $draft->forceFill(['status' => $status])->save();

        expect($this->start->handle($this->user, $draft, RunAiDraftStage::VISION))->toBe('started');

        AiDraftState::move($draft->id, [AiDraftStatus::Seeing], $status);
        expect($this->start->handle($this->user, $draft->refresh(), RunAiDraftStage::VISION))->toBe('started');

        AiDraftState::move($draft->id, [AiDraftStatus::Seeing], $status);
        expect($this->start->handle($this->user, $draft->refresh(), RunAiDraftStage::VISION))->toBe('limited');
        Queue::assertPushed(RunAiDraftStage::class, 2);
    })->with([AiDraftStatus::Failed, AiDraftStatus::Refused, AiDraftStatus::Capped]);

    it('refuses a stage from a state it cannot start from', function (AiDraftStatus $status, string $stage) {
        $draft = wizDraftWithPhotos($this->user);
        $draft->forceFill(['status' => $status, 'facts' => wizFacts(), 'result' => ['facts_confirmed' => true]])->save();

        expect($this->start->handle($this->user, $draft, $stage))->toBe('state')->and($draft->refresh()->status)->toBe($status);
        Queue::assertNothingPushed();
    })->with([
        [AiDraftStatus::Applied, RunAiDraftStage::VISION],
        [AiDraftStatus::Applying, RunAiDraftStage::WRITE],
        [AiDraftStatus::Expired, RunAiDraftStage::WRITE],
        [AiDraftStatus::Ready, RunAiDraftStage::VISION],
    ]);

    it('answers error for an unknown stage', function () {
        expect($this->start->handle($this->user, wizDraftWithPhotos($this->user), 'translate'))->toBe('error');
        Queue::assertNothingPushed();
    });

    it('refuses the vision stage without photos or with a photo file that cannot be re-encoded', function () {
        $empty = wizDraft($this->user, AiDraftStatus::Open);

        expect($this->start->handle($this->user, $empty, RunAiDraftStage::VISION))->toBe('no_images');

        $draft = wizDraftWithPhotos($this->user);
        file_put_contents(Storage::disk('local')->path(AiProductDraft::folder($draft->uuid).'/clean/u1-plain.jpg'), '<html>not an image</html>');
        $other = staffUser(Role::Admin);
        $draft->forceFill(['user_id' => $other->id])->save();

        expect($this->start->handle($other, $draft, RunAiDraftStage::VISION))->toBe('image')
            ->and(Storage::disk('local')->exists(AiProductDraft::folder($draft->uuid).'/vision'))->toBeFalse()
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Open);
        Queue::assertNothingPushed();
    });
});

describe('double click and races (PW-8)', function () {
    it('dispatches one job and one billed call for two clicks', function () {
        $draft = wizDraftWithPhotos($this->user);

        $first = $this->start->handle($this->user, $draft, RunAiDraftStage::VISION);
        $second = $this->start->handle($this->user, $draft->refresh(), RunAiDraftStage::VISION);

        expect($first)->toBe('started')->and($second)->toBe('pending');
        Queue::assertPushed(RunAiDraftStage::class, 1);
    });

    it('lets only one of two racing conditional updates win', function () {
        $draft = wizDraftWithPhotos($this->user);

        $first = AiDraftState::move($draft->id, AiDraftStatus::visionFrom(), AiDraftStatus::Seeing, [], $this->user->id);
        $second = AiDraftState::move($draft->id, AiDraftStatus::visionFrom(), AiDraftStatus::Seeing, [], $this->user->id);

        expect($first)->toBeTrue()->and($second)->toBeFalse();
    });

    it('does not move another user\'s draft', function () {
        $draft = wizDraftWithPhotos($this->user);

        expect(AiDraftState::move($draft->id, AiDraftStatus::visionFrom(), AiDraftStatus::Seeing, [], staffUser(Role::Admin)->id))->toBeFalse()
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Open);
    });
});

describe('guards in order', function () {
    it('refuses everything while the switch is off, without counting it', function () {
        wizOn(false);

        expect($this->start->handle($this->user, wizDraftWithPhotos($this->user), RunAiDraftStage::VISION))->toBe('disabled')
            ->and(wizUsage('adm_vision'))->toBeNull();
        Queue::assertNothingPushed();
    });

    it('refuses over the per-user and the global hourly limit and counts a capped hit', function (string $limit, string $key) {
        config(["chatbot.ai.product.limits.{$key}" => 1]);
        $other = staffUser(Role::Admin);
        $first = wizDraftWithPhotos($this->user);
        $second = wizDraftWithPhotos($other);
        $second->forceFill(['user_id' => $other->id])->save();

        expect($this->start->handle($this->user, $first, RunAiDraftStage::VISION))->toBe('started');

        $blocked = $limit === 'user' ? $this->user : $other;
        $draft = $limit === 'user' ? $first : $second;
        AiDraftState::move($first->id, [AiDraftStatus::Seeing], AiDraftStatus::Failed);

        expect($this->start->handle($blocked, $draft->refresh(), RunAiDraftStage::VISION))->toBe('limited')
            ->and(wizUsage('adm_vision')->capped)->toBe(1);
        Queue::assertPushed(RunAiDraftStage::class, 1);
    })->with([
        'per user' => ['user', 'vision_per_hour_user'],
        'global' => ['global', 'vision_per_hour_global'],
    ]);

    it('keeps the limits of the two stages apart', function () {
        config(['chatbot.ai.product.limits.vision_per_hour_user' => 1]);
        $draft = wizDraftWithPhotos($this->user);

        $this->start->handle($this->user, $draft, RunAiDraftStage::VISION);
        AiDraftState::move($draft->id, [AiDraftStatus::Seeing], AiDraftStatus::FactsReady, ['facts' => wizFacts(), 'result' => ['facts_confirmed' => true]]);

        expect($this->start->handle($this->user, $draft->refresh(), RunAiDraftStage::WRITE))->toBe('started');
    });

    it('allows one pending stage per user and two overall', function () {
        $mine = wizDraftWithPhotos($this->user);
        $another = wizDraftWithPhotos($this->user);

        expect($this->start->handle($this->user, $mine, RunAiDraftStage::VISION))->toBe('started')
            ->and($this->start->handle($this->user, $another->refresh(), RunAiDraftStage::VISION))->toBe('pending');

        config(['chatbot.ai.product.limits.pending_global' => 1]);
        $admin = staffUser(Role::Admin);
        $theirs = wizDraftWithPhotos($admin);

        expect($this->start->handle($admin, $theirs->refresh(), RunAiDraftStage::VISION))->toBe('busy');
        Queue::assertPushed(RunAiDraftStage::class, 1);
    });

    it('refuses with capped when the stage cap is already reached, without a reservation', function () {
        Setting::query()->create(['key' => ChatSettings::key('ai_adm_vision_daily_requests'), 'group' => 'chatbot', 'value' => 0]);
        ChatSettings::forget();

        expect($this->start->handle($this->user, wizDraftWithPhotos($this->user), RunAiDraftStage::VISION))->toBe('capped')
            ->and(wizUsage('adm_vision')->capped)->toBe(1)->and(wizUsage('adm_vision')->requests)->toBe(0);
        Queue::assertNothingPushed();
    });

    it('does not let the write cap block the vision stage', function () {
        Setting::query()->create(['key' => ChatSettings::key('ai_adm_write_daily_requests'), 'group' => 'chatbot', 'value' => 0]);
        ChatSettings::forget();

        expect($this->start->handle($this->user, wizDraftWithPhotos($this->user), RunAiDraftStage::VISION))->toBe('started');
    });
});
