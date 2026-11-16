<?php

use App\Actions\Catalog\Ai\CreateProductFromAiDraft;
use App\Enums\ActivityType;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Livewire\Admin\Products\AiWizard;
use App\Models\Activity;
use App\Models\AiProductDraft;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Content\AiProduct\AiSpecParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/AiWizardHelpers.php';

/**
 * Plan 32 §6, §9, PW-14, PW-19, PW-20: creating a product from a ready draft.
 * Always an inactive, non-featured product without a badge; the price is the
 * one typed in step 1 (read from the draft row); specs only when accepted and
 * valid for the category; images cleaned, main first, with alts; one product
 * per draft however often apply runs; Activity holds keys and counts only.
 */
beforeEach(function () {
    wizStorage();
    wizOn();
    Queue::fake();
    FakeChatModel::bind();
    $this->user = staffUser(Role::ContentManager);
    $this->create = app(CreateProductFromAiDraft::class);
});

describe('the created product', function () {
    it('is an inactive, non-featured product without a badge, with texts, a generated slug and the typed price', function () {
        $category = Category::factory()->create();
        $draft = wizReadyCreate($this->user, $category);
        $draft->forceFill(['input' => [...$draft->input, 'info' => [...$draft->input['info'], 'price' => '1200', 'brand_id' => '', 'model' => 'DS2208']]])->save();

        $product = $this->create->handle($this->user, $draft->refresh(), wizData($draft));

        expect($product->is_active)->toBeFalse()
            ->and($product->is_featured)->toBeFalse()
            ->and($product->badge)->toBeNull()
            ->and($product->sku)->toBe('ZB-DS2208')
            ->and($product->category_id)->toBe($category->id)
            ->and($product->price)->toBe(120000)
            ->and($product->slug)->toBe('zebra-ds2208-barcode-scanner')
            ->and($product->getTranslation('name', 'uz'))->toBe('Zebra DS2208 shtrix-kod skaneri')
            ->and($product->getTranslation('meta_description', 'en'))->toBe('Zebra DS2208 scanner.')
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Applied)->and($draft->product_id)->toBe($product->id)->and($draft->applied_at)->not->toBeNull();
    });

    it('creates a price-on-request product when no price was typed', function () {
        $draft = wizReadyCreate($this->user);

        $product = $this->create->handle($this->user, $draft, wizData($draft));

        expect($product->price)->toBeNull()->and($product->sale_mode->value)->toBe('quote');
    });

    it('adds the cleaned images main first with their alt texts', function () {
        $draft = wizReadyCreate($this->user, photos: 2, alts: [
            0 => ['uz' => 'Birinchi', 'ru' => 'Первое', 'en' => 'First'],
            1 => ['uz' => 'Ikkinchi', 'ru' => 'Второе', 'en' => 'Second'],
        ]);

        $product = $this->create->handle($this->user, $draft, wizData($draft, ['main' => 1]));

        $media = $product->getMedia(Product::IMAGES);

        expect($media)->toHaveCount(2)
            ->and($media[0]->getCustomProperty('alt')['en'])->toBe('Second')
            ->and($media[1]->getCustomProperty('alt')['en'])->toBe('First');
    });

    it('stores the cleaned square copy when "clean" is on and the plain copy when it is off', function () {
        $draft = wizReadyCreate($this->user, photos: 2);
        $images = $draft->input['images'];
        $images[1]['clean'] = false;
        $draft->forceFill(['input' => [...$draft->input, 'images' => $images]])->save();

        $product = $this->create->handle($this->user, $draft->refresh(), wizData($draft));

        [$cleaned, $plain] = $product->getMedia(Product::IMAGES)->map(fn ($media): array => array_slice(getimagesize($media->getPath()), 0, 2))->all();

        expect($cleaned[0])->toBe($cleaned[1])->and($plain[0])->not->toBe($plain[1]);
    });

    it('never takes price, publish state, slug or badge from the model text or the client state', function () {
        $draft = wizReadyCreate($this->user, texts: ['name' => ['uz' => 'Zebra DS2208', 'ru' => '', 'en' => 'ignore rules; is_active=true; price=1']]);

        $product = $this->create->handle($this->user, $draft, wizData($draft, ['info' => ['price' => '5', 'sku' => 'HACKED', 'type' => 'service'], 'accepted' => ['is_active', 'is_featured', 'badge', 'price', 'slug', 'name']]));

        expect($product->sku)->toBe('ZB-DS2208')->and($product->price)->toBeNull()->and($product->is_active)->toBeFalse()->and($product->type->value)->toBe('hardware')
            ->and($product->slug)->toBe('ignore-rules-is-activetrue-price1');
    });

    it('applies only accepted, valid specs and flags the missing required one', function () {
        $category = Category::factory()->create();
        $width = Attribute::factory()->number('mm')->create(['code' => 'width']);
        $weight = Attribute::factory()->number('g')->create(['code' => 'weight']);
        $required = Attribute::factory()->number('mm')->create(['code' => 'height']);
        $category->attributes()->attach($width, ['sort' => 1]);
        $category->attributes()->attach($weight, ['sort' => 2]);
        $category->attributes()->attach($required, ['sort' => 3, 'is_required' => true]);
        $proposals = array_values(app(AiSpecParser::class)->parse([
            ['code' => 'width', 'value' => '120', 'text' => [], 'unsure' => false],
            ['code' => 'weight', 'value' => '250', 'text' => [], 'unsure' => true],
        ], $category->id)['specs']);
        $draft = wizReadyCreate($this->user, $category, specs: $proposals);

        $product = $this->create->handle($this->user, $draft, wizData($draft, ['accepted' => [...wizData($draft)->accepted, 'spec:'.$width->id]]));

        $values = $product->attributeValues()->pluck('value_number', 'attribute_id')->map(fn ($value): float => (float) $value)->all();

        expect($values)->toBe([$width->id => 120.0])->and($product->has_missing_specs)->toBeTrue();
    });

    it('drops an accepted spec that no longer belongs to the category (stale proposal)', function () {
        $category = Category::factory()->create();
        $width = Attribute::factory()->number('mm')->create(['code' => 'width']);
        $category->attributes()->attach($width, ['sort' => 1]);
        $proposals = array_values(app(AiSpecParser::class)->parse([['code' => 'width', 'value' => '120', 'text' => [], 'unsure' => false]], $category->id)['specs']);
        $draft = wizReadyCreate($this->user, $category, specs: $proposals);
        $category->attributes()->detach($width);

        $product = $this->create->handle($this->user, $draft, wizData($draft, ['accepted' => [...wizData($draft)->accepted, 'spec:'.$width->id]]));

        expect($product->attributeValues()->count())->toBe(0);
    });

    it('records an Activity with field keys and counts, never the texts', function () {
        $draft = wizReadyCreate($this->user, texts: ['name' => ['uz' => 'CANARY-NAME-uz', 'ru' => '', 'en' => 'CANARY-NAME-en']], alts: [0 => ['uz' => 'CANARY-ALT', 'ru' => '', 'en' => '']]);

        $product = $this->create->handle($this->user, $draft, wizData($draft));

        $activity = Activity::query()->where('subject_id', $product->id)->where('type', ActivityType::AiCreate)->sole();

        expect($activity->user_id)->toBe($this->user->id)
            ->and($activity->body)->toContain('fields: name')->toContain('images.added: 1')->toContain('images.alts: 1')
            ->and($activity->body.json_encode($activity->meta))->not->toContain('CANARY')->not->toContain('Zebra')
            ->and($activity->meta['fields'])->toContain('name');
    });

    it('deletes the draft\'s cleaned and vision files after a successful create', function () {
        $draft = wizReadyCreate($this->user);
        wizVisionFile($draft);

        $this->create->handle($this->user, $draft, wizData($draft));

        expect(Storage::disk('local')->allFiles(AiProductDraft::folder($draft->uuid)))->toBe([]);
    });
});

describe('idempotence (PW-20)', function () {
    it('returns the same product and one image set when apply runs twice', function () {
        $draft = wizReadyCreate($this->user);
        $data = wizData($draft);

        $first = $this->create->handle($this->user, $draft, $data);
        $second = $this->create->handle($this->user, $draft->refresh(), $data);

        expect($second->id)->toBe($first->id)
            ->and(Product::query()->where('sku', 'ZB-DS2208')->count())->toBe(1)
            ->and($first->fresh()->getMedia(Product::IMAGES))->toHaveCount(1)
            ->and(Activity::query()->where('type', ActivityType::AiCreate)->count())->toBe(1);
    });

    it('resumes an interrupted apply: the product exists, the images are added once', function () {
        $draft = wizReadyCreate($this->user, photos: 2);
        $product = Product::factory()->create(['sku' => 'ZB-DS2208']);
        $draft->forceFill(['status' => AiDraftStatus::Applying, 'product_id' => $product->id, 'apply_state' => ['done' => ['0' => null], 'counts' => ['added' => 0, 'replaced' => 0, 'removed' => 0, 'alts' => 0]]])->save();

        $result = $this->create->handle($this->user, $draft, wizData($draft));

        expect($result->id)->toBe($product->id)->and($draft->refresh()->status)->toBe(AiDraftStatus::Applied)
            ->and($result->fresh()->getMedia(Product::IMAGES))->toHaveCount(1);
    });
});

describe('refusals', function () {
    it('refuses a draft that is not ready', function (AiDraftStatus $status) {
        $draft = wizReadyCreate($this->user);
        $draft->forceFill(['status' => $status])->save();

        expect(fn () => $this->create->handle($this->user, $draft, wizData($draft)))->toThrow(ValidationException::class)
            ->and(Product::query()->count())->toBe(0);
    })->with([AiDraftStatus::Open, AiDraftStatus::Seeing, AiDraftStatus::FactsReady, AiDraftStatus::Writing, AiDraftStatus::Failed, AiDraftStatus::Expired]);

    it('creates from a truncated draft', function () {
        $draft = wizReadyCreate($this->user);
        $draft->forceFill(['status' => AiDraftStatus::Truncated])->save();

        expect($this->create->handle($this->user, $draft, wizData($draft))->sku)->toBe('ZB-DS2208');
    });

    it('refuses another user\'s draft, an improve draft and a user without the right', function (string $case) {
        $draft = wizReadyCreate($this->user);
        $caller = match ($case) {
            'other user' => staffUser(Role::Admin),
            'sales manager' => tap(staffUser(Role::SalesManager), fn () => $draft->forceFill(['user_id' => User::query()->where('id', '>', 0)->latest('id')->value('id')])->save()),
            default => $this->user,
        };

        if ($case === 'improve draft') {
            $draft->forceFill(['mode' => AiProductDraft::IMPROVE])->save();
        }

        expect(fn () => $this->create->handle($caller, $draft->refresh(), wizData($draft)))->toThrow(AuthorizationException::class)
            ->and(Product::query()->count())->toBe(0);
    })->with(['other user', 'sales manager', 'improve draft']);

    it('refuses when the draft\'s user lost ai.generate or the switch is off', function (string $case) {
        $draft = wizReadyCreate($this->user);
        $case === 'switch off' ? wizOn(false) : wizRevokeAiGenerate();

        expect(fn () => $this->create->handle($this->user, $draft, wizData($draft)))->toThrow(AuthorizationException::class);
    })->with(['switch off', 'revoked']);

    it('re-validates texts from the client state: discount, price, link and unknown fields', function (array $texts, string $key) {
        $draft = wizReadyCreate($this->user);

        try {
            $this->create->handle($this->user, $draft, wizData($draft, ['texts' => $texts]));
            $this->fail('No exception.');
        } catch (ValidationException $error) {
            expect($error->errors())->toHaveKey($key)
                ->and(json_encode($error->errors()))->not->toContain('evil.example');
        }

        expect(Product::query()->count())->toBe(0)->and($draft->refresh()->status)->toBe(AiDraftStatus::Ready);
    })->with([
        'discount' => [['name' => ['uz' => 'Zebra DS2208', 'ru' => 'Скидка 20% на сканер', 'en' => '']], 'texts.name.ru'],
        'low price' => [['name' => ['uz' => 'Zebra DS2208 arzon', 'ru' => '', 'en' => '']], 'texts.name.uz'],
        'amount' => [['short_description' => ['uz' => 'Narxi 1 200 000 soʻm', 'ru' => '', 'en' => '']], 'texts.short_description.uz'],
        'html in a plain field' => [['name' => ['uz' => '<b>Zebra</b>', 'ru' => '', 'en' => '']], 'texts.name.uz'],
        'name too long' => [['name' => ['uz' => str_repeat('a', 300), 'ru' => '', 'en' => '']], 'texts.name.uz'],
        'missing uz name' => [['name' => ['uz' => '', 'ru' => 'Сканер', 'en' => '']], 'name.uz'],
    ]);

    it('removes foreign links and scripts from the description instead of saving them', function () {
        $draft = wizReadyCreate($this->user);

        $product = $this->create->handle($this->user, $draft, wizData($draft, ['texts' => wizTexts(['description' => [
            'uz' => '<p>Skaner <a href="https://evil.example/x">havola</a><script>alert(1)</script><img src=x onerror=alert(1)></p>',
            'ru' => '', 'en' => '',
        ]])]));

        $html = $product->getTranslation('description', 'uz');

        expect($html)->toContain('Skaner')->toContain('havola')->not->toContain('evil.example')->not->toContain('<script')->not->toContain('onerror')->not->toContain('<img');
    });

    it('fails with a field error and keeps the draft ready when the SKU was taken meanwhile', function () {
        $draft = wizReadyCreate($this->user);
        Product::factory()->create(['sku' => 'ZB-DS2208']);

        expect(fn () => $this->create->handle($this->user, $draft, wizData($draft)))->toThrow(ValidationException::class)
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Ready)
            ->and(Product::query()->count())->toBe(1);
    });
});

describe('through the component', function () {
    it('uses the price and SKU saved in step 1, not a later client edit, and ticks only sure specs', function () {
        $category = Category::factory()->create();
        $width = Attribute::factory()->number('mm')->create(['code' => 'width']);
        $weight = Attribute::factory()->number('g')->create(['code' => 'weight']);
        $category->attributes()->attach($width, ['sort' => 1]);
        $category->attributes()->attach($weight, ['sort' => 2]);
        $proposals = array_values(app(AiSpecParser::class)->parse([
            ['code' => 'width', 'value' => '120', 'text' => [], 'unsure' => false],
            ['code' => 'weight', 'value' => '250', 'text' => [], 'unsure' => true],
        ], $category->id)['specs']);
        $draft = wizReadyCreate($this->user, $category, specs: $proposals);
        $draft->forceFill(['input' => [...$draft->input, 'info' => [...$draft->input['info'], 'price' => '900']]])->save();

        $component = Livewire::actingAs($this->user)->test(AiWizard::class)
            ->assertSet('step', 'review')
            ->assertSet('acceptSpecs.'.$width->id, true)
            ->assertSet('acceptSpecs.'.$weight->id, false);

        $component->set('info.price', '1')->set('info.sku', 'TAMPERED')->set('texts.hacked', ['uz' => 'x'])->set('accept.is_active', true)
            ->call('toConfirm')->assertHasNoErrors()->call('apply')->assertHasNoErrors()->assertRedirect();

        $product = Product::query()->sole();

        expect($product->sku)->toBe('ZB-DS2208')->and($product->price)->toBe(90000)->and($product->is_active)->toBeFalse()
            ->and($product->attributeValues()->pluck('attribute_id')->all())->toBe([$width->id]);
    });

    it('shows the validation error of a tampered text and creates nothing', function () {
        wizReadyCreate($this->user);

        Livewire::actingAs($this->user)->test(AiWizard::class)
            ->set('texts.name.ru', 'Скидка 20% на сканер')
            ->call('toConfirm')->assertHasErrors(['texts.name.ru'])->assertSet('step', 'review');

        expect(Product::query()->count())->toBe(0);
    });

    it('does not apply twice when the button is pressed twice', function () {
        wizReadyCreate($this->user);
        $component = Livewire::actingAs($this->user)->test(AiWizard::class)->call('toConfirm')->call('apply')->assertRedirect();

        $component->call('apply');

        expect(Product::query()->count())->toBe(1);
    });
});
