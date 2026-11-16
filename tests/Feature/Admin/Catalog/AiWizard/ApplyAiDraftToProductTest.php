<?php

use App\Actions\Catalog\AddProductImages;
use App\Actions\Catalog\Ai\ApplyAiDraftToProduct;
use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Actions\Catalog\UpdateProductImages;
use App\Enums\ActivityType;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\AiProductDraft;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Content\AiProduct\AiSpecParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/AiWizardHelpers.php';

/**
 * Plan 32 §6, §9, PW-14, PW-19 - PW-22: applying a ready draft to an existing
 * product. Only accepted keys are written; publish state, slug, featured flag,
 * badge and synced prices stay; replace / remove of images only when
 * explicitly confirmed; a conflict is a change to something the apply would
 * write (field snapshot), not a price sync; one apply per draft.
 */
beforeEach(function () {
    wizStorage();
    wizOn();
    Queue::fake();
    FakeChatModel::bind();
    $this->user = staffUser(Role::ContentManager);
    $this->apply = app(ApplyAiDraftToProduct::class);
    $this->product = Product::factory()->create([
        'sku' => 'ZB-1',
        'is_active' => true,
        'is_featured' => true,
        'badge' => 'new',
        'name' => ['uz' => 'Eski nom', 'ru' => 'Старое имя', 'en' => 'Old name'],
        'short_description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'meta_title' => ['uz' => 'Eski meta', 'ru' => '', 'en' => ''],
    ]);
});

/**
 * A ready improve draft for the product (snapshot taken now, as the wizard opens).
 *
 * @param  array<string, array<string, string>>  $texts
 * @param  list<array<string, mixed>>  $specs
 */
function wizReadyImprove(User $user, Product $product, array $texts = [], array $specs = [], array $alts = []): AiProductDraft
{
    return AiProductDraft::factory()->forUser($user)->improving($product)->withFacts(wizFacts())
        ->withProposals(wizTexts($texts), $specs, $alts)->create();
}

function wizAddImages(Product $product, int $count = 1): void
{
    for ($i = 1; $i <= $count; $i++) {
        app(AddProductImages::class)->handle($product, [UploadedFile::fake()->image("existing{$i}.jpg", 500 + $i * 10, 300)]);
    }
}

describe('what is written', function () {
    it('writes only the accepted fields and leaves publish state, slug, flags and badge alone', function () {
        $slug = $this->product->slug;
        $draft = wizReadyImprove($this->user, $this->product);

        $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['short_description', 'meta_description']]));

        $product = $this->product->refresh();

        expect($product->getTranslation('short_description', 'en'))->toBe('A reliable 2D scanner.')
            ->and($product->getTranslation('meta_description', 'uz'))->toBe('Zebra DS2208 skaneri.')
            // Proposed but not accepted.
            ->and($product->getTranslation('name', 'en'))->toBe('Old name')
            ->and($product->getTranslation('meta_title', 'uz'))->toBe('Eski meta')
            ->and($product->is_active)->toBeTrue()->and($product->is_featured)->toBeTrue()->and($product->badge->value)->toBe('new')
            ->and($product->slug)->toBe($slug)->and($product->sku)->toBe('ZB-1')
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Applied);
    });

    it('overwrites a filled field only when it is accepted', function () {
        $draft = wizReadyImprove($this->user, $this->product);

        $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['name']]));

        expect($this->product->refresh()->getTranslation('name', 'uz'))->toBe('Zebra DS2208 shtrix-kod skaneri')
            ->and((string) $this->product->getTranslation('short_description', 'uz'))->toBe('');
    });

    it('keeps the price of a price-synced product', function () {
        $synced = Product::factory()->priceSynced()->create(['price' => 450000, 'short_description' => ['uz' => '', 'ru' => '', 'en' => '']]);
        $draft = wizReadyImprove($this->user, $synced);

        $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['short_description'], 'info' => ['price' => '1']]));

        expect($synced->refresh()->price)->toBe(450000)->and($synced->price_synced_at)->not->toBeNull();
    });

    it('applies only the spec proposals that were ticked; an unsure one needs an explicit tick', function () {
        $category = Category::factory()->create();
        $width = Attribute::factory()->number('mm')->create(['code' => 'width']);
        $weight = Attribute::factory()->number('g')->create(['code' => 'weight']);
        $category->attributes()->attach($width, ['sort' => 1]);
        $category->attributes()->attach($weight, ['sort' => 2]);
        $product = Product::factory()->inCategory($category)->create();
        $proposals = array_values(app(AiSpecParser::class)->parse([
            ['code' => 'width', 'value' => '120', 'text' => [], 'unsure' => false],
            ['code' => 'weight', 'value' => '250', 'text' => [], 'unsure' => true],
        ], $category->id)['specs']);
        $draft = wizReadyImprove($this->user, $product, specs: $proposals);

        $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['spec:'.$width->id]]));

        expect($product->attributeValues()->pluck('attribute_id')->all())->toBe([$width->id])
            ->and((float) $product->attributeValues()->first()->value_number)->toBe(120.0);
    });

    it('applies a changed step 1 value and no other', function () {
        $draft = wizReadyImprove($this->user, $this->product);
        $draft->forceFill(['input' => [...$draft->input, 'info' => [...$draft->input['info'], 'model' => 'DS2208-NEW']]])->save();

        $this->apply->handle($this->user, $draft->refresh(), wizData($draft, ['accepted' => []]));

        expect($this->product->refresh()->model)->toBe('DS2208-NEW')->and($this->product->sku)->toBe('ZB-1');
    });

    it('records an Activity with field keys and counts only', function () {
        $draft = wizReadyImprove($this->user, $this->product, ['short_description' => ['uz' => 'CANARY-SHORT', 'ru' => '', 'en' => 'CANARY-EN']]);

        $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['short_description']]));

        $activity = Activity::query()->where('subject_id', $this->product->id)->where('type', ActivityType::AiImprove)->sole();

        expect($activity->body)->toContain('fields: short_description')->toContain('specs: 0')
            ->and($activity->body.json_encode($activity->meta))->not->toContain('CANARY')->not->toContain('Zebra');
    });

    it('re-validates texts and refuses discount wording from the client state without writing', function () {
        $draft = wizReadyImprove($this->user, $this->product);

        expect(fn () => $this->apply->handle($this->user, $draft, wizData($draft, [
            'accepted' => ['short_description'],
            'texts' => ['short_description' => ['uz' => 'Hozir chegirma bilan', 'ru' => '', 'en' => '']],
        ])))->toThrow(ValidationException::class);

        expect($this->product->refresh()->getTranslation('short_description', 'uz'))->toBe('')->and($draft->refresh()->status)->toBe(AiDraftStatus::Ready);
    });
});

describe('images (PW-22)', function () {
    it('replaces an existing image only when the replace was confirmed, keeping its place and a copy of the original', function (bool $confirmed) {
        wizAddImages($this->product, 2);
        $old = $this->product->getMedia(Product::IMAGES)->first();
        $draft = wizReadyImprove($this->user, $this->product->refresh());
        app(PrepareAiDraftImages::class)->addUpload($draft->refresh(), UploadedFile::fake()->image('new.jpg', 640, 480));
        $draft->refresh();
        $flags = [
            0 => ['clean' => false, 'action' => 'keep', 'replaces' => null, 'confirmed' => false],
            1 => ['clean' => false, 'action' => 'keep', 'replaces' => null, 'confirmed' => false],
            2 => ['clean' => true, 'action' => 'replace', 'replaces' => 0, 'confirmed' => $confirmed],
        ];

        $this->apply->handle($this->user, $draft, wizData($draft, ['images' => $flags, 'accepted' => ['images'], 'main' => null]));

        $ids = $this->product->refresh()->getMedia(Product::IMAGES)->pluck('id')->all();

        expect($ids)->toHaveCount(2)
            ->and(in_array($old->id, $ids, true))->toBe(! $confirmed)
            ->and(Storage::disk('local')->exists(AiProductDraft::folder($draft->uuid).'/kept/m'.$old->id.'.jpg'))->toBe($confirmed);
    })->with([true, false]);

    it('removes an existing image only when the removal was confirmed', function (bool $confirmed) {
        wizAddImages($this->product, 2);
        $draft = wizReadyImprove($this->user, $this->product->refresh());
        $flags = [
            0 => ['clean' => false, 'action' => 'remove', 'replaces' => null, 'confirmed' => $confirmed],
            1 => ['clean' => false, 'action' => 'keep', 'replaces' => null, 'confirmed' => false],
        ];

        $this->apply->handle($this->user, $draft, wizData($draft, ['images' => $flags, 'accepted' => ['images'], 'main' => null]));

        expect($this->product->refresh()->getMedia(Product::IMAGES))->toHaveCount($confirmed ? 1 : 2);
    })->with([true, false]);

    it('replaces an existing image with its cleaned copy only when "clean" is on AND confirmed; otherwise it stays untouched', function (bool $clean, bool $confirmed, bool $replaced) {
        wizAddImages($this->product, 1);
        $old = $this->product->getMedia(Product::IMAGES)->first();
        $draft = wizReadyImprove($this->user, $this->product->refresh());

        if ($clean) {
            app(PrepareAiDraftImages::class)->cleanExisting($draft, $this->product, $old->id);
        }

        $flags = [0 => ['clean' => $clean, 'action' => 'keep', 'replaces' => null, 'confirmed' => $confirmed]];

        $this->apply->handle($this->user, $draft, wizData($draft, ['images' => $flags, 'accepted' => ['images'], 'main' => null]));

        $media = $this->product->refresh()->getMedia(Product::IMAGES);
        [$width, $height] = getimagesize($media->first()->getPath());

        expect($media)->toHaveCount(1)
            ->and($media->first()->id === $old->id)->toBe(! $replaced)
            ->and($width === $height)->toBe($replaced);
    })->with([
        'toggle off' => [false, false, false],
        'toggle off, confirmed' => [false, true, false],
        'toggle on, not confirmed' => [true, false, false],
        'toggle on and confirmed' => [true, true, true],
    ]);

    it('adds a new photo with its alt text and leaves the images alone when "images" is not accepted', function (bool $accepted) {
        wizAddImages($this->product, 1);
        $draft = wizReadyImprove($this->user, $this->product->refresh(), alts: [1 => ['uz' => 'Yangi rasm', 'ru' => 'Новое фото', 'en' => 'New photo']]);
        app(PrepareAiDraftImages::class)->addUpload($draft->refresh(), UploadedFile::fake()->image('new.jpg', 640, 480));
        $draft->refresh();
        $flags = [
            0 => ['clean' => false, 'action' => 'keep', 'replaces' => null, 'confirmed' => false],
            1 => ['clean' => true, 'action' => 'add', 'replaces' => null, 'confirmed' => false],
        ];

        $this->apply->handle($this->user, $draft, wizData($draft, ['images' => $flags, 'accepted' => $accepted ? ['images', 'alts'] : ['short_description'], 'main' => null]));

        $media = $this->product->refresh()->getMedia(Product::IMAGES);

        expect($media)->toHaveCount($accepted ? 2 : 1)
            ->and($accepted ? $media->last()->getCustomProperty('alt')['en'] : 'none')->toBe($accepted ? 'New photo' : 'none');
    })->with([true, false]);

    it('only ever touches media of the locked product, never a foreign media id in the draft row', function () {
        $foreign = Product::factory()->create();
        wizAddImages($foreign, 1);
        $foreignMedia = $foreign->getMedia(Product::IMAGES)->first();
        $draft = wizReadyImprove($this->user, $this->product);
        $draft->forceFill(['input' => [...$draft->input, 'images' => [['source' => 'media', 'ref' => $foreignMedia->id, 'clean' => false, 'action' => 'remove', 'replaces' => null]]]])->save();
        $flags = [0 => ['clean' => false, 'action' => 'remove', 'replaces' => null, 'confirmed' => true]];

        $this->apply->handle($this->user, $draft->refresh(), wizData($draft, ['images' => $flags, 'accepted' => ['images'], 'main' => null]));

        expect($foreign->refresh()->getMedia(Product::IMAGES))->toHaveCount(1);
    });
});

describe('conflicts (PW-21)', function () {
    it('refuses and writes nothing when an accepted field changed since the wizard opened', function () {
        $draft = wizReadyImprove($this->user, $this->product);
        $this->product->setTranslation('short_description', 'uz', 'Boshqa matn')->save();

        expect(fn () => $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['short_description', 'meta_description']])))->toThrow(ValidationException::class);

        expect($this->product->refresh()->getTranslation('short_description', 'uz'))->toBe('Boshqa matn')
            ->and($this->product->getTranslation('meta_description', 'uz'))->toBe('')
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Ready)
            ->and(Activity::query()->where('type', ActivityType::AiImprove)->count())->toBe(0);
    });

    it('does not treat a change to a field that is not accepted as a conflict, and keeps it', function () {
        $draft = wizReadyImprove($this->user, $this->product);
        $this->product->setTranslation('name', 'uz', 'Boshqa nom')->save();

        $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['short_description']]));

        expect($this->product->refresh()->getTranslation('name', 'uz'))->toBe('Boshqa nom')
            ->and($this->product->getTranslation('short_description', 'uz'))->toBe('Ishonchli 2D skaner.');
    });

    it('does not treat a price sync in between as a conflict', function () {
        $synced = Product::factory()->priceSynced()->create(['price' => 100000, 'short_description' => ['uz' => '', 'ru' => '', 'en' => '']]);
        $draft = wizReadyImprove($this->user, $synced);
        $synced->forceFill(['price' => 120000])->save();
        $synced->touch();

        $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['short_description']]));

        expect($synced->refresh()->price)->toBe(120000)->and($synced->getTranslation('short_description', 'uz'))->toBe('Ishonchli 2D skaner.');
    });

    it('refuses image changes when the image set was changed elsewhere, but not text-only changes', function () {
        wizAddImages($this->product, 2);
        $draft = wizReadyImprove($this->user, $this->product->refresh());
        $first = $this->product->getMedia(Product::IMAGES)->first();
        app(UpdateProductImages::class)->move($this->product, $first->id, 1);
        $flags = [
            0 => ['clean' => false, 'action' => 'remove', 'replaces' => null, 'confirmed' => true],
            1 => ['clean' => false, 'action' => 'keep', 'replaces' => null, 'confirmed' => false],
        ];

        expect(fn () => $this->apply->handle($this->user, $draft, wizData($draft, ['images' => $flags, 'accepted' => ['images'], 'main' => null])))->toThrow(ValidationException::class)
            ->and($this->product->refresh()->getMedia(Product::IMAGES))->toHaveCount(2);

        $this->apply->handle($this->user, $draft->refresh(), wizData($draft, ['accepted' => ['short_description']]));

        expect($this->product->refresh()->getTranslation('short_description', 'uz'))->toBe('Ishonchli 2D skaner.');
    });

    it('refuses accepted specs when the spec values changed elsewhere', function () {
        $category = Category::factory()->create();
        $width = Attribute::factory()->number('mm')->create(['code' => 'width']);
        $category->attributes()->attach($width, ['sort' => 1]);
        $product = Product::factory()->inCategory($category)->create();
        $proposals = array_values(app(AiSpecParser::class)->parse([['code' => 'width', 'value' => '120', 'text' => [], 'unsure' => false]], $category->id)['specs']);
        $draft = wizReadyImprove($this->user, $product, specs: $proposals);
        $product->attributeValues()->create(['attribute_id' => $width->id, 'value_number' => 77]);

        expect(fn () => $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['spec:'.$width->id]])))->toThrow(ValidationException::class)
            ->and((float) $product->attributeValues()->sole()->value_number)->toBe(77.0);
    });
});

describe('idempotence and state (PW-20)', function () {
    it('applies once: a second apply returns the product, adds no second image set and no second Activity', function () {
        $draft = wizReadyImprove($this->user, $this->product);
        app(PrepareAiDraftImages::class)->addUpload($draft->refresh(), UploadedFile::fake()->image('new.jpg', 640, 480));
        $draft->refresh();
        $data = wizData($draft, ['accepted' => ['short_description', 'images'], 'images' => [0 => ['clean' => true, 'action' => 'add', 'replaces' => null, 'confirmed' => false]], 'main' => null]);

        $first = $this->apply->handle($this->user, $draft, $data);
        $second = $this->apply->handle($this->user, $draft->refresh(), $data);

        expect($second->id)->toBe($first->id)
            ->and($this->product->refresh()->getMedia(Product::IMAGES))->toHaveCount(1)
            ->and(Activity::query()->where('type', ActivityType::AiImprove)->count())->toBe(1);
    });

    it('refuses a draft that is not ready', function (AiDraftStatus $status) {
        $draft = wizReadyImprove($this->user, $this->product);
        $draft->forceFill(['status' => $status])->save();

        expect(fn () => $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['name']])))->toThrow(ValidationException::class);
        expect($this->product->refresh()->getTranslation('name', 'uz'))->toBe('Eski nom');
    })->with([AiDraftStatus::Open, AiDraftStatus::FactsReady, AiDraftStatus::Writing, AiDraftStatus::Failed, AiDraftStatus::Expired]);
});

describe('authorization at apply (PW-6, PW-7)', function () {
    it('refuses when update was revoked, nothing is written', function () {
        $draft = wizReadyImprove($this->user, $this->product);
        $this->user->roles->first()->revokePermissionTo('products.update');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(fn () => $this->apply->handle($this->user, $draft, wizData($draft, ['accepted' => ['name']])))->toThrow(AuthorizationException::class);
        expect($this->product->refresh()->getTranslation('name', 'uz'))->toBe('Eski nom');
    });

    it('refuses another user, a create draft, a role without access, a missing switch and a deleted product', function (string $case) {
        $draft = wizReadyImprove($this->user, $this->product);
        $caller = $this->user;

        match ($case) {
            'other user' => $caller = staffUser(Role::Admin),
            'create draft' => $draft->forceFill(['mode' => AiProductDraft::CREATE, 'product_id' => null])->save(),
            'sales manager' => tap($draft->forceFill(['user_id' => ($caller = User::factory()->withRole(Role::SalesManager)->create())->id]))->save(),
            'switch off' => wizOn(false),
            'deleted product' => $this->product->delete(),
        };

        expect(fn () => $this->apply->handle($caller, $draft->refresh(), wizData($draft, ['accepted' => ['name']])))->toThrow(AuthorizationException::class);
    })->with(['other user', 'create draft', 'sales manager', 'switch off', 'deleted product']);
});
