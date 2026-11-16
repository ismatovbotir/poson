<?php

require_once __DIR__.'/AiWizardAbuseKit.php';

use App\Actions\Catalog\Ai\AiDraftLock;
use App\Actions\Catalog\Ai\ApplyAiDraftToProduct;
use App\Actions\Catalog\Ai\ConfirmAiDraftFacts;
use App\Actions\Catalog\Ai\CreateProductFromAiDraft;
use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Actions\Catalog\Ai\PruneAiDrafts;
use App\Actions\Catalog\Ai\StartAiDraftStage;
use App\Actions\Catalog\UpdateProductImages;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Jobs\RunAiDraftStage;
use App\Livewire\Admin\Chatbot\Settings as ChatbotSettingsScreen;
use App\Livewire\Admin\Products\AiWizard;
use App\Models\AiProductDraft;
use App\Models\Product;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeChatModel;

/**
 * Plan 32 fix round: the attempts limiter, the job claim lock, the stale
 * clock at job start, the interrupted-apply panel, drafts that already own a
 * product, trashed products on resume, resume authorization, cancel twice,
 * the settings caps given junk, previews under preview/ and the 0-rows
 * re-read of AiDraftLock::writeInput(). In-process, fakes only.
 */
beforeEach(function () {
    wizStorage();
    wizOn();
    Queue::fake();
    $this->fake = FakeChatModel::bind();
    $this->user = abzCm();
});

describe('limiter and locks', function () {
    it('counts every attempt, refusals included, and limits the 31st per user within ten minutes', function () {
        $draft = AiProductDraft::factory()->forUser($this->user)->withInfo(wizInfo())->create();
        $start = app(StartAiDraftStage::class);

        for ($i = 1; $i <= 30; $i++) {
            expect($start->handle($this->user, $draft->refresh(), RunAiDraftStage::WRITE))->toBe('state');
        }

        expect(RateLimiter::attempts("ai-wizard:attempts:{$this->user->id}"))->toBe(30)
            ->and($start->handle($this->user, $draft->refresh(), RunAiDraftStage::WRITE))->toBe('limited')
            ->and(RateLimiter::attempts("ai-wizard:attempts:{$this->user->id}"))->toBe(30)
            ->and(wizUsage('adm_write')->capped)->toBe(1)
            // Another user has an own counter.
            ->and($start->handle(staffUser(Role::Admin), $draft->refresh(), RunAiDraftStage::WRITE))->toBe('state');

        RateLimiter::clear("ai-wizard:attempts:{$this->user->id}");

        expect($start->handle($this->user, $draft->refresh(), RunAiDraftStage::WRITE))->toBe('state');
        Queue::assertNothingPushed();
    });

    it('lets one run hold the claim lock ai-draft:run:{id}: a second delivery makes no call and the lock is released after', function () {
        $draft = wizSeeing($this->user, 1);
        $claim = Cache::lock("ai-draft:run:{$draft->id}", 90);
        $claim->get();
        $fake = FakeChatModel::bind(wizFactsAnswer());

        wizRun($draft, RunAiDraftStage::VISION);

        expect($fake->calls())->toBe(0)->and($draft->refresh()->status)->toBe(AiDraftStatus::Seeing);

        $claim->release();
        wizRun($draft, RunAiDraftStage::VISION);

        $again = Cache::lock("ai-draft:run:{$draft->id}", 5);

        expect($fake->calls())->toBe(1)->and($draft->refresh()->status)->toBe(AiDraftStatus::FactsReady)->and($again->get())->toBeTrue();
    });

    it('does not revive a stale stage at job start: it is failed and no model call is made', function () {
        $draft = wizSeeing($this->user, 1);
        AiProductDraft::query()->whereKey($draft->id)->toBase()->update(['updated_at' => now()->subHours(2)]);
        $fake = FakeChatModel::bind(wizFactsAnswer());

        wizRun($draft, RunAiDraftStage::VISION);

        expect($fake->calls())->toBe(0)->and($draft->refresh()->status)->toBe(AiDraftStatus::Failed)->and($draft->error)->toBe('error')
            ->and(wizUsage('adm_vision'))->toBeNull();
    });
});

describe('interrupted apply', function () {
    it('shows the interrupted panel with Open product and Discard after the prune marked a stale applying draft', function (string $mode) {
        $product = Product::factory()->create();
        $draft = $mode === 'improve'
            ? AiProductDraft::factory()->forUser($this->user)->improving($product)->withFacts(wizFacts())->withProposals(wizTexts())->create()
            : wizReadyCreate($this->user);
        $draft->forceFill(['status' => AiDraftStatus::Applying, 'product_id' => $product->id, 'apply_state' => ['done' => [], 'counts' => []]])->save();
        abzAge($draft, 3 * 3600);

        app(PruneAiDrafts::class)->handle();

        expect($draft->refresh()->status)->toBe(AiDraftStatus::Failed)->and($draft->error)->toBe('apply_interrupted');

        $component = $mode === 'improve' ? Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $product]) : Livewire::actingAs($this->user)->test(AiWizard::class);

        $component->assertSeeHtml('data-apply-interrupted')->assertSeeHtml('data-open-product')->assertSeeHtml(route('admin.products.edit', $product))
            ->assertDontSeeHtml('data-stage-error');

        $component->call('cancel');

        expect($draft->refresh()->status)->toBe(AiDraftStatus::Expired);
    })->with(['create', 'improve']);

    it('refuses look() with the state refusal on a started apply, after the photo hint, and prefill() changes nothing', function () {
        $product = Product::factory()->create();
        $draft = AiProductDraft::factory()->forUser($this->user)->improving($product)->create();
        $draft->forceFill(['status' => AiDraftStatus::Failed, 'error' => 'apply_interrupted', 'apply_state' => ['done' => []]])->save();

        // Without the hint the hint error comes first; with it the state refusal.
        $component = Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $product]);
        $component->call('look')->assertHasErrors(['upload']);
        $component->call('acknowledgePhotoHint')->call('look')->assertDispatched('toast');

        expect($draft->refresh()->status)->toBe(AiDraftStatus::Failed)->and($draft->facts)->toBeNull()->and($draft->error)->toBe('apply_interrupted')
            ->and(app(ConfirmAiDraftFacts::class)->prefill($draft, wizFacts()))->toBeFalse()
            ->and($draft->refresh()->facts)->toBeNull();
        Queue::assertNothingPushed();

        $draft->forceFill(['status' => AiDraftStatus::Open, 'error' => null, 'apply_state' => null])->save();

        expect(app(ConfirmAiDraftFacts::class)->prefill($draft, wizFacts()))->toBeTrue()->and($draft->refresh()->status)->toBe(AiDraftStatus::FactsReady);
    });

    it('cancel and startOver show the state refusal, with no redirect, when the draft is already discarded', function (string $method) {
        $component = abzOpen($this->user);
        $component->call($method)->assertRedirect();

        expect(AiProductDraft::query()->sole()->status)->toBe(AiDraftStatus::Expired);

        $component->call($method)->assertNoRedirect()->assertDispatched('toast');
    })->with(['cancel', 'startOver']);

    it('refuses a resumed create after the user lost the right to update the product, without touching it', function () {
        $draft = wizReadyCreate($this->user, null, 2);
        $this->partialMock(UpdateProductImages::class, fn ($mock) => $mock->shouldReceive('setAlt')->once()->andThrow(new RuntimeException('crash')));

        expect(fn () => app(CreateProductFromAiDraft::class)->handle($this->user, $draft, wizData($draft)))->toThrow(RuntimeException::class);

        app()->forgetInstance(UpdateProductImages::class);
        $product = Product::query()->findOrFail($draft->refresh()->product_id);
        $this->user->roles->first()->revokePermissionTo('products.update');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(fn () => app(CreateProductFromAiDraft::class)->handle($this->user, $draft->refresh(), wizData($draft)))->toThrow(AuthorizationException::class)
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Applying)->and($product->refresh()->getMedia(Product::IMAGES))->toHaveCount(1);
    });
});

describe('drafts that own a product', function () {
    it('loads a create draft with a server-set product_id by uuid, and every edit of it answers state', function () {
        $draft = abzPhotoDraft($this->user, 1);
        $draft->forceFill(['product_id' => Product::factory()->create()->id])->save();
        $input = $draft->refresh()->input;

        $this->actingAs($this->user)->get(route('admin.products.ai.create', ['draft' => $draft->uuid]))->assertOk();
        $this->actingAs(abzCm())->get(route('admin.products.ai.create', ['draft' => $draft->uuid]))->assertNotFound();

        $component = Livewire::withQueryParams(['draft' => $draft->uuid])->actingAs($this->user)->test(AiWizard::class)->call('acknowledgePhotoHint');

        expect($component->get('uuid'))->toBe($draft->uuid);

        $component->call('look')->assertDispatched('toast');
        $component->call('toggleClean', 0)->call('toggleRemove', 0)->call('setMain', 0);
        $component->set('upload', UploadedFile::fake()->image('late.jpg', 300, 300));
        $component->call('saveInfo')->assertHasErrors(['draft']);

        expect($draft->refresh()->input)->toBe($input)->and($draft->status)->toBe(AiDraftStatus::Open);
        Queue::assertNothingPushed();
    });

    it('refuses a resumed apply, with the state error, when the product was trashed meanwhile', function (string $mode) {
        $product = Product::factory()->create();
        $draft = $mode === 'improve' ? abzReadyImprove($this->user, $product) : wizReadyCreate($this->user, null, 0);
        $draft->forceFill(['status' => AiDraftStatus::Applying, 'product_id' => $product->id, 'apply_state' => ['done' => [], 'counts' => []]])->save();
        $product->delete();

        $run = fn () => $mode === 'improve'
            ? app(ApplyAiDraftToProduct::class)->handle($this->user, $draft->refresh(), wizData($draft, ['accepted' => ['short_description']]))
            : app(CreateProductFromAiDraft::class)->handle($this->user, $draft->refresh(), wizData($draft));

        try {
            $run();
            $thrown = null;
        } catch (ValidationException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->errors()['draft'][0])->toBe(__('admin.ai_wizard.errors.state'))->and($draft->refresh()->status)->toBe(AiDraftStatus::Applying);
    })->with(['create', 'improve']);
});

describe('settings caps given junk', function () {
    it('answers a validation error, not a server error, for an empty or non-numeric cap', function (string $field, string $value) {
        Livewire::actingAs(staffUser(Role::Admin))->test(ChatbotSettingsScreen::class)
            ->set("form.{$field}", $value)->call('save')->assertOk()->assertHasErrors(["form.{$field}"]);
    })->with(function () {
        foreach (['ai_adm_vision_daily_requests', 'ai_adm_vision_daily_tokens', 'ai_adm_write_daily_requests', 'ai_adm_write_daily_tokens'] as $field) {
            foreach (['' => 'empty', 'abc' => 'letters'] as $value => $label) {
                yield "{$field} {$label}" => [$field, (string) $value];
            }
        }
    });
});

describe('previews', function () {
    it('keeps previews under ai-drafts/{uuid}/preview/ only and removes them with an improve apply', function () {
        $product = abzProduct(1);
        $draft = AiProductDraft::factory()->forUser($this->user)->improving($product)->withFacts(wizFacts())->withProposals(wizTexts())->create();
        $prepare = app(PrepareAiDraftImages::class);
        $prepare->addUpload($draft->refresh(), UploadedFile::fake()->image('new.jpg', 400, 300));

        expect($prepare->preview($draft->refresh(), $prepare->uploadFile($draft, 1, true)))->toStartWith('data:image/jpeg;base64,');

        $previews = array_values(array_filter(abzFiles($draft), fn (string $file): bool => str_starts_with($file, 'preview/')));

        expect($previews)->toHaveCount(1)->and($previews[0])->toMatch('~^preview/u1-clean-\d+\.jpg$~')
            ->and(DB::table('cache')->where('key', 'like', '%preview%')->count())->toBe(0);

        app(ApplyAiDraftToProduct::class)->handle($this->user, $draft->refresh(), wizData($draft, ['accepted' => ['short_description', 'images']]));

        expect($draft->refresh()->status)->toBe(AiDraftStatus::Applied)->and(abzFiles($draft))->toBe([]);
    });
});

describe('writeInput() when the database reports zero changed rows', function () {
    afterEach(function () {
        DB::statement('DROP TRIGGER IF EXISTS abz_zero_rows');
    });

    it('counts an identical write as success by re-reading the row, and a different one as a normal write', function () {
        $draft = AiProductDraft::factory()->forUser($this->user)->withInfo(wizInfo())->create();
        $input = $draft->refresh()->input;
        abzZeroRowsTrigger();

        // The trigger really makes the identical update match nothing.
        expect(AiProductDraft::query()->whereKey($draft->id)->update(['input' => json_encode($input)]))->toBe(0);

        $reordered = array_reverse($input, true);

        expect(AiDraftLock::writeInput($draft, $input))->toBeTrue()
            ->and(AiDraftLock::writeInput($draft, $reordered))->toBeTrue()
            ->and($draft->refresh()->input)->toEqual($input)
            ->and(AiDraftLock::writeInput($draft, [...$input, 'notes' => 'changed']))->toBeTrue()
            ->and($draft->refresh()->input['notes'])->toBe('changed');
    });

    it('never counts an identical write on a frozen draft, an applied one or one with an apply_state', function (AiDraftStatus $status, bool $applyState) {
        $draft = AiProductDraft::factory()->forUser($this->user)->withInfo(wizInfo())->create();
        $draft->forceFill(['status' => $status, 'apply_state' => $applyState ? ['done' => []] : null])->save();
        abzZeroRowsTrigger();

        expect(AiDraftLock::writeInput($draft, $draft->refresh()->input))->toBeFalse();
    })->with([
        'seeing' => [AiDraftStatus::Seeing, false],
        'applying' => [AiDraftStatus::Applying, false],
        'expired' => [AiDraftStatus::Expired, false],
        'open with apply_state' => [AiDraftStatus::Open, true],
    ]);

    it('lets an identical facts confirm succeed too', function () {
        $draft = abzSetStatus(wizSeeing($this->user, 1), AiDraftStatus::FactsReady, ['facts' => wizFacts(), 'result' => ['facts_confirmed' => false]]);
        $facts = wizFacts();
        abzZeroRowsTrigger();

        expect(app(ConfirmAiDraftFacts::class)->handle($draft->refresh(), $facts))->toBe([])
            ->and(app(ConfirmAiDraftFacts::class)->handle($draft->refresh(), $facts))->toBe([]);
    });
});

/**
 * MySQL reports changed rows: make SQLite do the same, an update that changes nothing matches 0 rows.
 */
function abzZeroRowsTrigger(): void
{
    DB::statement('CREATE TRIGGER IF NOT EXISTS abz_zero_rows BEFORE UPDATE ON ai_product_drafts WHEN OLD.input IS NEW.input AND OLD.facts IS NEW.facts AND OLD.result IS NEW.result AND OLD.status IS NEW.status AND OLD.apply_state IS NEW.apply_state BEGIN SELECT RAISE(IGNORE); END');
}
