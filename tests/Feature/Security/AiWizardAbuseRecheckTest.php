<?php

require_once __DIR__.'/AiWizardAbuseKit.php';

use App\Actions\Catalog\Ai\AiDraftLock;
use App\Actions\Catalog\Ai\AiDraftState;
use App\Actions\Catalog\Ai\ApplyAiDraftToProduct;
use App\Actions\Catalog\Ai\CreateProductFromAiDraft;
use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Actions\Catalog\Ai\PruneAiDrafts;
use App\Actions\Catalog\Ai\SaveAiDraftInfo;
use App\Actions\Catalog\Ai\StartAiDraftStage;
use App\Actions\Catalog\UpdateProductImages;
use App\Enums\AiDraftStatus;
use App\Jobs\RunAiDraftStage;
use App\Livewire\Admin\Products\AiWizard;
use App\Models\AiProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\ChatModel;
use App\Support\Content\AiProduct\ProductTextGuard;
use App\Support\Media\ImageRejected;
use App\Support\Media\ProductImageCleaner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\CallbackChatModel;
use Tests\Support\FakeChatModel;
use Tests\Support\ImageFixtures;

/**
 * Plan 32 §15 abuse cases 36-51 (the re-check of 2026-11-16): stale stages,
 * the claim and draft locks, frozen drafts, interrupted applies, the attempts
 * limiter, previews, the image memory guard, the open-drafts cap, in-place
 * clean confirmation, tampered state types and the text guard's promise and
 * userinfo rules. In-process: SQLite, fakes, a private fake disk, no network.
 */
beforeEach(function () {
    wizStorage();
    wizOn();
    Queue::fake();
    $this->fake = FakeChatModel::bind();
    $this->user = abzCm();
});

describe('stale stages (cases 36-37)', function () {
    it('case 36: a stale seeing or writing stage is failed by the poll, the poll attribute goes, fresh rows stay', function (AiDraftStatus $status) {
        $stale = $status === AiDraftStatus::Seeing ? wizSeeing($this->user, 1) : wizDraft($this->user, $status);
        $stranger = abzCm();
        $fresh = AiProductDraft::factory()->forUser($stranger)->status($status)->create();
        $component = abzResume($this->user);

        expect($component->html())->toContain('wire:poll.2s="poll"');

        abzAge($stale, AiDraftState::staleSeconds() + 5);
        $component->call('poll');

        expect($stale->refresh()->status)->toBe(AiDraftStatus::Failed)->and($stale->error)->toBe('error')
            ->and($fresh->refresh()->status)->toBe($status)
            ->and($component->html())->not->toContain('wire:poll');
    })->with([AiDraftStatus::Seeing, AiDraftStatus::Writing]);

    it('case 36: stale rows are not counted as pending, fresh ones are', function () {
        config(['chatbot.ai.product.limits.pending_global' => 2]);
        $start = app(StartAiDraftStage::class);
        $staleOnes = [AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Seeing)->create(), AiProductDraft::factory()->forUser(abzCm())->status(AiDraftStatus::Writing)->create()];

        foreach ($staleOnes as $row) {
            abzAge($row, AiDraftState::staleSeconds() + 5);
        }

        // Two stale rows count neither for the user nor for the global limit.
        expect($start->handle($this->user, abzPhotoDraft($this->user), RunAiDraftStage::VISION))->toBe('started');

        // The now fresh pending stage does count.
        expect($start->handle($this->user, abzPhotoDraft($this->user), RunAiDraftStage::VISION))->toBe('pending');
        Queue::assertPushed(RunAiDraftStage::class, 1);
    });

    it('case 37: a job that finds its draft failed as stale makes no call; two runs at once make one call and one reservation', function () {
        $stale = wizSeeing($this->user, 1);
        abzAge($stale, AiDraftState::staleSeconds() + 5);
        AiDraftState::failStale($stale->id);

        $fake = FakeChatModel::bind(wizFactsAnswer());
        wizRun($stale->refresh(), RunAiDraftStage::VISION);

        expect($fake->calls())->toBe(0)->and($stale->refresh()->status)->toBe(AiDraftStatus::Failed)->and(wizUsage('adm_vision'))->toBeNull();

        // A second delivery arrives while the first one is inside the model call.
        $draft = wizSeeing($this->user, 1);
        $model = new CallbackChatModel(fn () => wizRun($draft->refresh(), RunAiDraftStage::VISION), wizFactsAnswer());
        app()->instance(ChatModel::class, $model);

        wizRun($draft, RunAiDraftStage::VISION);

        expect($model->requests)->toHaveCount(1)->and(wizUsage('adm_vision')->requests)->toBe(1)->and($draft->refresh()->status)->toBe(AiDraftStatus::FactsReady);
    });
});

describe('draft lock (cases 38-39)', function () {
    it('case 38: while the draft lock is held elsewhere every write refuses without a change, and after release edits persist in turn', function () {
        Sleep::fake(syncWithCarbon: true);
        $product = abzProduct(1);
        $draft = abzPhotoDraft($this->user, 1);
        $ready = wizReadyCreate($this->user);
        $improve = abzReadyImprove($this->user, $product);
        // The wizard resumes the latest open draft: expire the ready and improve drafts so it picks the photo draft.
        AiProductDraft::query()->whereKey([$ready->id, $improve->id])->update(['expires_at' => now()->subMinute()]);
        $component = abzResume($this->user);
        $input = $draft->refresh()->input;
        $lock = Cache::lock("ai-draft:apply:{$draft->id}", 300);

        expect($lock->get())->toBeTrue();

        $component->set('upload', UploadedFile::fake()->image('late.jpg', 300, 300))->assertHasErrors(['upload']);
        $component->call('toggleClean', 0)->call('toggleRemove', 0)->call('setMain', 0)->call('saveInfo')->call('look');

        expect($draft->refresh()->input)->toBe($input)->and($draft->status)->toBe(AiDraftStatus::Open);
        Queue::assertNothingPushed();

        // Create and apply refuse as a validation error, nothing is created.
        $readyLock = Cache::lock("ai-draft:apply:{$ready->id}", 300);
        $improveLock = Cache::lock("ai-draft:apply:{$improve->id}", 300);
        $readyLock->get();
        $improveLock->get();
        $title = $ready->input['info']['sku'];

        foreach ([
            fn () => app(CreateProductFromAiDraft::class)->handle($this->user, $ready->refresh(), wizData($ready)),
            fn () => app(ApplyAiDraftToProduct::class)->handle($this->user, $improve->refresh(), wizData($improve, ['accepted' => ['short_description']])),
        ] as $run) {
            try {
                $run();
                $thrown = null;
            } catch (ValidationException $exception) {
                $thrown = $exception;
            }

            expect($thrown)->toBeInstanceOf(ValidationException::class)->and($thrown->errors())->toHaveKey('draft');
        }

        expect(Product::query()->where('sku', $title)->exists())->toBeFalse()->and($ready->refresh()->status)->toBe(AiDraftStatus::Ready)
            ->and($improve->refresh()->status)->toBe(AiDraftStatus::Ready);

        $lock->release();

        // Sequential after the release: the upload and the flag edit both persist.
        $component->set('upload', UploadedFile::fake()->image('first.jpg', 300, 300));
        $component->call('toggleRemove', 1);

        $images = $draft->refresh()->input['images'];

        expect($images)->toHaveCount(2)->and($images[1]['action'])->toBe('remove')->and($images[0]['action'])->toBe('add');
    });

    it('case 39: writeInput() on a frozen or applying draft returns false and leaves the input identical', function (AiDraftStatus $status, bool $applyState, bool $productId) {
        $draft = AiProductDraft::factory()->forUser($this->user)->withInfo(wizInfo())->create();
        $columns = ['status' => $status];

        if ($applyState) {
            $columns['apply_state'] = ['done' => []];
        }

        if ($productId) {
            $columns['product_id'] = Product::factory()->create()->id;
        }

        $draft->forceFill($columns)->save();
        $input = $draft->refresh()->input;

        expect(AiDraftLock::writeInput($draft, [...$input, 'notes' => 'changed', 'images' => [['source' => 'upload', 'ref' => 9, 'clean' => true, 'action' => 'add', 'replaces' => null]]]))->toBeFalse()
            ->and($draft->refresh()->input)->toBe($input);
    })->with([
        'seeing' => [AiDraftStatus::Seeing, false, false],
        'writing' => [AiDraftStatus::Writing, false, false],
        'applying' => [AiDraftStatus::Applying, false, false],
        'applied' => [AiDraftStatus::Applied, false, false],
        'expired' => [AiDraftStatus::Expired, false, false],
        'open with an apply_state' => [AiDraftStatus::Open, true, false],
        'failed create draft with a product' => [AiDraftStatus::Failed, false, true],
    ]);
});

describe('images missing for a stage (case 40)', function () {
    it('case 40: a missing vision copy or a vision_map that does not match fails the stage as no_images before any reservation', function (string $case) {
        $draft = wizSeeing($this->user, 2);

        match ($case) {
            'one copy missing' => unlink(Storage::disk('local')->path(AiProductDraft::folder($draft->uuid).'/vision/2.jpg')),
            'all copies pruned' => Storage::disk('local')->deleteDirectory(AiProductDraft::folder($draft->uuid).'/vision'),
            'map longer than the copies' => $draft->forceFill(['input' => [...$draft->input, 'vision_map' => [0, 1, 2]]])->save(),
            'empty map' => $draft->forceFill(['input' => [...$draft->input, 'vision_map' => []]])->save(),
        };

        $fake = FakeChatModel::bind(wizFactsAnswer([], 2));
        wizRun($draft->refresh(), RunAiDraftStage::VISION);

        expect($fake->calls())->toBe(0)->and($draft->refresh()->status)->toBe(AiDraftStatus::Failed)->and($draft->error)->toBe('no_images')
            ->and(wizUsage('adm_vision'))->toBeNull();
    })->with(['one copy missing', 'all copies pruned', 'map longer than the copies', 'empty map']);
});

describe('interrupted apply (cases 41-42)', function () {
    it('case 41: a stale applying draft is failed by the prune, keeps kept/, and can no longer create, start or take uploads', function () {
        $draft = wizReadyCreate($this->user, null, 1);
        $other = Product::factory()->create(['sku' => 'ALREADY-1']);
        $draft->forceFill(['status' => AiDraftStatus::Applying, 'product_id' => $other->id, 'apply_state' => ['done' => [], 'counts' => []]])->save();
        $kept = Storage::disk('local')->path(AiProductDraft::folder($draft->uuid).'/kept/m5.jpg');
        mkdir(dirname($kept), 0777, true);
        file_put_contents($kept, 'original');
        abzAge($draft, 3 * 3600);

        app(PruneAiDrafts::class)->handle();

        $draft->refresh();

        expect($draft->status)->toBe(AiDraftStatus::Failed)->and($draft->error)->toBe('apply_interrupted')->and(is_file($kept))->toBeTrue();

        $before = Product::query()->count();

        expect(fn () => app(CreateProductFromAiDraft::class)->handle($this->user, $draft, wizData($draft)))->toThrow(ValidationException::class)
            ->and(Product::query()->count())->toBe($before)
            ->and(app(StartAiDraftStage::class)->handle($this->user, $draft->refresh(), RunAiDraftStage::VISION))->toBe('state')
            ->and(app(StartAiDraftStage::class)->handle($this->user, $draft->refresh(), RunAiDraftStage::WRITE))->toBe('state');

        $input = $draft->refresh()->input;

        expect(fn () => app(PrepareAiDraftImages::class)->addUpload($draft, UploadedFile::fake()->image('late.jpg', 300, 300)))->toThrow(ValidationException::class)
            ->and($draft->refresh()->input)->toBe($input);
        Queue::assertNothingPushed();
    });

    it('case 42: a resumed apply follows the stored plan and a crash after the image was added never adds it twice', function () {
        $draft = wizReadyCreate($this->user, null, 2);
        $data = wizData($draft);

        // The first run dies right after AddProductImages recorded the first medium (while setting its alt).
        $this->partialMock(UpdateProductImages::class, fn ($mock) => $mock->shouldReceive('setAlt')->once()->andThrow(new RuntimeException('crash after the image was added')));

        expect(fn () => app(CreateProductFromAiDraft::class)->handle($this->user, $draft, $data))->toThrow(RuntimeException::class);

        $draft->refresh();
        $product = Product::query()->findOrFail($draft->product_id);

        expect($draft->status)->toBe(AiDraftStatus::Applying)->and($product->getMedia(Product::IMAGES))->toHaveCount(1)
            ->and($draft->apply_state['stored'])->toHaveCount(1)->and($draft->apply_state['plan']['flags'][0]['action'])->toBe('add');

        // The resume comes with a changed page state (everything removed): the stored plan wins.
        app()->forgetInstance(UpdateProductImages::class);
        $changed = wizData($draft, ['images' => [
            0 => ['clean' => false, 'action' => 'remove', 'replaces' => null, 'confirmed' => true],
            1 => ['clean' => false, 'action' => 'remove', 'replaces' => null, 'confirmed' => true],
        ], 'main' => 1]);

        $again = app(CreateProductFromAiDraft::class)->handle($this->user, $draft->refresh(), $changed);

        expect($again->id)->toBe($product->id)->and($again->getMedia(Product::IMAGES))->toHaveCount(2)
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Applied)->and(Product::query()->count())->toBe(1);

        // And a third call changes nothing.
        app(CreateProductFromAiDraft::class)->handle($this->user, $draft->refresh(), $changed);

        expect(Media::query()->where('model_id', $product->id)->where('collection_name', Product::IMAGES)->count())->toBe(2);
    });
});

describe('attempts and previews (cases 43-44)', function () {
    it('case 43: fifty look() attempts while busy encode nothing and are limited after the 30th', function () {
        config(['chatbot.ai.product.limits.pending_global' => 1]);
        $counter = new class extends ProductImageCleaner
        {
            public int $vision = 0;

            public function vision(string $source, string $target): void
            {
                $this->vision++;
                parent::vision($source, $target);
            }
        };
        $draft = abzPhotoDraft($this->user, 2);
        AiProductDraft::factory()->forUser(abzCm())->status(AiDraftStatus::Seeing)->create();
        app()->instance(ProductImageCleaner::class, $counter);
        $start = app(StartAiDraftStage::class);

        $outcomes = [];

        for ($i = 0; $i < 50; $i++) {
            $outcomes[] = $start->handle($this->user, $draft->refresh(), RunAiDraftStage::VISION);
        }

        expect(array_count_values($outcomes))->toBe(['busy' => 30, 'limited' => 20])
            ->and($counter->vision)->toBe(0)->and(wizUsage('adm_vision')->capped)->toBe(20)
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Open);
        Queue::assertNothingPushed();

        // The component path is bounded the same way.
        Livewire::actingAs($this->user)->test(AiWizard::class)->call('acknowledgePhotoHint')->call('look')->assertOk();
        expect($counter->vision)->toBe(0);
    });

    it('case 44: after discard, apply and prune no preview file and no preview cache row is left', function () {
        Cache::setDefaultDriver('database');
        $applied = wizReadyCreate($this->user, null, 1);
        $pruned = abzPhotoDraft(abzCm(), 1);
        $discarded = abzPhotoDraft($this->user, 2);

        foreach ([$discarded, $applied, $pruned] as $draft) {
            $prepare = app(PrepareAiDraftImages::class);

            foreach ($draft->input['images'] as $image) {
                expect($prepare->preview($draft, $prepare->uploadFile($draft, $image['ref'], true)))->toStartWith('data:image/jpeg;base64,');
            }

            expect(array_filter(abzFiles($draft), fn (string $file): bool => str_starts_with($file, 'preview/')))->not->toBe([]);
        }

        // The photo step renders the tiles (previews) of the user's latest open draft, then the user discards it.
        $component = Livewire::actingAs($this->user)->test(AiWizard::class)->call('acknowledgePhotoHint');
        $uuid = $component->get('uuid');
        $component->call('cancel');

        $draftOfComponent = AiProductDraft::query()->where('uuid', $uuid)->sole();

        expect($draftOfComponent->status)->toBe(AiDraftStatus::Expired)->and(abzFiles($draftOfComponent))->toBe([]);

        app(CreateProductFromAiDraft::class)->handle($this->user, $applied->refresh(), wizData($applied));
        AiProductDraft::query()->whereKey($pruned->id)->update(['expires_at' => now()->subMinute()]);
        app(PruneAiDrafts::class)->handle();

        expect(abzFiles($applied->refresh()))->toBe([])
            ->and(abzFiles($pruned))->toBe([])->and(abzFolderExists($pruned))->toBeFalse()
            ->and(DB::table('cache')->where('key', 'like', '%preview%')->count())->toBe(0);
    });
});

describe('image memory and rejections (cases 45-46)', function () {
    it('case 45: a 4000 x 4000 JPEG, PNG or WebP is refused as memory before decoding at 160M and cleaned at 256M', function (string $type) {
        $path = Storage::disk('local')->path("abuse/big.{$type}");
        $canvas = ImageFixtures::canvas(4000, 4000, 250, 250, 250);
        $dark = (int) imagecolorallocate($canvas, 20, 20, 20);
        imagefilledrectangle($canvas, 1500, 1500, 2500, 2500, $dark);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        match ($type) {
            'jpg' => imagejpeg($canvas, $path, 70),
            'png' => imagepng($canvas, $path, 9),
            'webp' => imagewebp($canvas, $path, 60),
        };
        imagedestroy($canvas);

        // A memory_limit of 160M / 256M with about 32M already in use by the request.
        $used = memory_get_usage(true);
        $reason = null;

        try {
            (new ProductImageCleaner($used + 128 * 1024 * 1024))->clean($path, Storage::disk('local')->path("abuse/out-small.{$type}.jpg"));
        } catch (ImageRejected $rejected) {
            $reason = $rejected->reason;
        }

        expect($reason)->toBe('memory')->and(is_file(Storage::disk('local')->path("abuse/out-small.{$type}.jpg")))->toBeFalse();

        (new ProductImageCleaner($used + 224 * 1024 * 1024))->clean($path, Storage::disk('local')->path("abuse/out-big.{$type}.jpg"));

        expect(is_file(Storage::disk('local')->path("abuse/out-big.{$type}.jpg")))->toBeTrue();
    })->with(['jpg', 'png', 'webp']);

    it('case 46: cleaning an existing image over 5 MB or over 4000 px is a field error, not a server error', function (string $kind) {
        $product = Product::factory()->create();
        $path = Storage::disk('local')->path("abuse/original-{$kind}.jpg");

        if ($kind === 'wide') {
            ImageFixtures::jpeg(ImageFixtures::canvas(4100, 100), $path);
        } else {
            ImageFixtures::bordered($path, 600, 400, 100);
            file_put_contents($path, str_repeat("\0", ProductImageCleaner::MAX_BYTES + 1024), FILE_APPEND);
        }

        $media = $product->addMedia($path)->preservingOriginal()->toMediaCollection(Product::IMAGES);
        $component = abzOpen($this->user, $product->refresh());

        $component->call('toggleClean', 0)->assertOk()->assertHasErrors(['upload']);

        $draft = AiProductDraft::query()->sole();

        expect($draft->input['images'][0]['clean'])->toBeFalse()->and($draft->input['images'][0]['ref'])->toBe($media->id)
            ->and(abzFiles($draft))->toBe([]);
    })->with(['wide', 'heavy']);
});

describe('open drafts and the info baseline (cases 47-48)', function () {
    it('case 47: a step 1 field changed after the page opened is a conflict at the first save and opens no draft', function () {
        $product = Product::factory()->create(['model' => 'OPENED']);
        $component = Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $product]);

        Product::query()->whereKey($product->id)->update(['model' => 'CHANGED-ELSEWHERE']);

        $component->call('saveInfo')->assertHasErrors(['draft']);

        expect(AiProductDraft::query()->count())->toBe(0)->and($component->errors()->first('draft'))->toBe(__('admin.ai_wizard.errors.conflict'));
    });

    it('case 48: the 6th open draft is refused, closed drafts do not count, and a held start lock opens none', function () {
        AiProductDraft::factory()->forUser($this->user)->count(2)->create();
        AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Expired)->count(2)->create();
        AiProductDraft::factory()->forUser($this->user)->status(AiDraftStatus::Applied)->create();
        AiProductDraft::factory()->forUser($this->user)->expired()->create();
        $save = app(SaveAiDraftInfo::class);
        $info = fn (): array => wizInfo();
        $live = fn (): int => AiProductDraft::query()->where('user_id', $this->user->id)->whereNotIn('status', ['expired', 'applied'])->where('expires_at', '>', now())->count();

        // Two live drafts: three more openings pass, the sixth is refused.
        foreach ([1, 2, 3] as $n) {
            $save->handle($this->user, null, AiProductDraft::CREATE, null, $info(), '');
        }

        expect($live())->toBe(5);

        try {
            $save->handle($this->user, null, AiProductDraft::CREATE, null, $info(), '');
            $thrown = null;
        } catch (ValidationException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->errors()['draft'][0])->toBe(__('admin.ai_wizard.errors.too_many_drafts', ['max' => 5]))->and($live())->toBe(5);

        // The count and the insert run under the user's start lock: held elsewhere, nothing is opened.
        Sleep::fake(syncWithCarbon: true);
        AiProductDraft::query()->where('user_id', $this->user->id)->orderBy('id')->limit(2)->update(['status' => AiDraftStatus::Expired->value]);
        $lock = Cache::lock(StartAiDraftStage::userLockKey($this->user), 60);
        $lock->get();
        $before = AiProductDraft::query()->count();

        expect(fn () => $save->handle($this->user, null, AiProductDraft::CREATE, null, $info(), ''))->toThrow(ValidationException::class)
            ->and(AiProductDraft::query()->count())->toBe($before);

        $lock->release();
        $save->handle($this->user, null, AiProductDraft::CREATE, null, $info(), '');

        expect(AiProductDraft::query()->count())->toBe($before + 1);
    });
});

describe('in place clean and tampered state types (cases 49-50)', function () {
    it('case 49: an in-place clean without the confirmation leaves the image, with it replaces it in place and keeps the original', function (bool $confirmed) {
        $product = abzProduct(2);
        $original = $product->getMedia(Product::IMAGES)->first();
        $hash = md5_file($original->getPath());
        abzOpen($this->user, $product)->call('toggleClean', 0)->assertHasNoErrors();
        $draft = AiProductDraft::query()->sole();
        $draft->forceFill(['status' => AiDraftStatus::Ready, 'facts' => wizFacts(), 'result' => ['facts_confirmed' => true, 'texts' => wizTexts(), 'specs' => [], 'alts' => [], 'messages' => []]])->save();

        expect(abzFiles($draft))->toContain("clean/m{$original->id}-clean.jpg");

        $component = abzResume($this->user, $product)->set('accept.images', true);

        if ($confirmed) {
            $component->set('confirmed.0', true);
        }

        $component->call('toConfirm')->call('apply');

        $media = $product->refresh()->getMedia(Product::IMAGES);

        expect($draft->refresh()->status)->toBe(AiDraftStatus::Applied)->and($media)->toHaveCount(2);

        if ($confirmed) {
            expect($media->first()->id)->not->toBe($original->id)->and(Media::query()->whereKey($original->id)->exists())->toBeFalse()
                ->and(abzFiles($draft))->toContain("kept/m{$original->id}.jpg");
        } else {
            expect($media->first()->id)->toBe($original->id)->and(md5_file($media->first()->getPath()))->toBe($hash);
        }
    })->with([false, true]);

    it('case 50: a confirmation that is not the boolean true never confirms a removal', function (mixed $value, bool $removed) {
        $product = abzProduct(1);
        $original = $product->getMedia(Product::IMAGES)->first();
        abzOpen($this->user, $product)->call('toggleRemove', 0);
        $draft = AiProductDraft::query()->sole();
        $draft->forceFill(['status' => AiDraftStatus::Ready, 'facts' => wizFacts(), 'result' => ['facts_confirmed' => true, 'texts' => wizTexts(), 'specs' => [], 'alts' => [], 'messages' => []]])->save();

        abzResume($this->user, $product)->set('accept.images', true)->set('confirmed.0', $value)->call('toConfirm')->call('apply');

        expect($draft->refresh()->status)->toBe(AiDraftStatus::Applied)
            ->and(Media::query()->whereKey($original->id)->exists())->toBe(! $removed);
    })->with([
        'string false' => ['false', false],
        'string true' => ['true', false],
        'string 1' => ['1', false],
        'string on' => ['on', false],
        'int 1' => [1, false],
        'boolean true' => [true, true],
    ]);

    it('case 50: a string info, an object for views and an array for a text spec answer with an error or are ignored, never a server error', function () {
        wizReadyCreate($this->user);
        $component = abzResume($this->user);

        $component->set('info', 'not an array')->assertOk()->assertHasErrors(['info']);
        $component->set('facts.views', ['a' => ['b' => 'c']])->assertOk()->assertHasErrors(['facts.views']);
        $component->set('specs.5.text.uz', ['x'])->assertOk();
        $component->set('confirmed', 'false')->assertOk()->assertHasErrors(['confirmed']);
        $component->set('accept', ['name' => ['x']])->assertOk()->assertHasErrors(['accept']);

        expect($component->get('info'))->toBeArray()->and($component->get('confirmed'))->toBeArray()->and($component->get('specs.5.text.uz'))->not->toBeArray();

        $component->call('toConfirm')->assertOk()->call('apply')->assertOk();
    });
});

describe('text guard (case 51)', function () {
    it('case 51: an invented commercial promise is a warning, ordinary service copy is not', function (string $text, bool $promise) {
        $checked = (new ProductTextGuard)->check($text);

        expect($checked['errors'])->toBe([])->and(in_array('promise', $checked['warnings'], true))->toBe($promise);
    })->with([
        'free delivery' => ['Free delivery across Uzbekistan', true],
        'free setup' => ['Free installation and setup included', true],
        'russian free' => ['Бесплатная доставка по городу', true],
        'uzbek free' => ['Bepul yetkazib berish', true],
        'warranty with a number' => ['Warranty: 24 months', true],
        'number then warranty' => ['2-year warranty', true],
        'russian warranty' => ['Гарантия 12 месяцев', true],
        'official distributor' => ['Official distributor of Zebra', true],
        'russian official dealer' => ['Официальный дилер Zebra', true],
        'uzbek official dealer' => ['Rasmiy distribyutor', true],
        'certified' => ['Certified for retail use', true],
        'plain service copy' => ['Expert selection, setup and integration, fast support', false],
        'official warranty without a number' => ['Backed by the official warranty', false],
    ]);

    it('case 51: a link whose user info names the own host is removed by sanitize, guard and sanitize', function (string $text) {
        config(['app.url' => 'https://poson.uz']);
        $guard = new ProductTextGuard;

        foreach ([false, true] as $html) {
            $checked = $guard->check($html ? "<p>{$text}</p>" : $text, $html);

            expect(mb_strtolower($checked['text']))->not->toContain('evil.example');
        }
    })->with([
        'plain url' => ['See https://poson.uz@evil.example/buy now'],
        'bare address' => ['Mail poson.uz@evil.example today'],
        'anchor' => ['<a href="https://poson.uz@evil.example">Buy</a>'],
        'unclosed encoded anchor' => ['<a href="https:&#47;&#47;poson.uz@evil.example">Buy now'],
        'upper case host' => ['<a href="HTTPS://POSON.UZ@EVIL.EXAMPLE/x">Buy</a>'],
    ]);

    it('case 51: a promise in a write answer is kept for the human with a warning message', function () {
        $draft = wizDraft($this->user, AiDraftStatus::Writing);
        $draft->forceFill(['input' => [...$draft->input, 'images' => [['source' => 'upload', 'ref' => 1, 'clean' => true, 'action' => 'add', 'replaces' => null]]]])->save();
        FakeChatModel::bind(wizWriteAnswer(['short_description' => ['uz' => 'Bepul yetkazib berish.', 'ru' => 'Бесплатная доставка.', 'en' => 'Free delivery.']]));

        wizRun($draft, RunAiDraftStage::WRITE);

        $draft->refresh();

        expect($draft->status)->toBe(AiDraftStatus::Ready)->and($draft->result['texts']['short_description']['en'])->toBe('Free delivery.')
            ->and(collect($draft->result['messages'])->pluck('message')->all())->toContain('admin.ai_wizard.guard.promise');
    });
});
