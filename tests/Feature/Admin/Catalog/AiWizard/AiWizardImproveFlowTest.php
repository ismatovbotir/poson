<?php

use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Jobs\RunAiDraftStage;
use App\Livewire\Admin\Products\AiWizard;
use App\Models\AiProductDraft;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Support\Content\AiProduct\AiSpecParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeChatModel;

require_once __DIR__.'/AiWizardHelpers.php';

/**
 * Plan 32 §5, §9 "Improve mode": the review and confirm steps of the wizard
 * as a person uses them - "only empty fields" on by default, unsure specs
 * never pre-ticked, the live-product notice, stage A only for new photos.
 */
beforeEach(function () {
    wizStorage();
    wizOn();
    Queue::fake();
    $this->fake = FakeChatModel::bind();
    $this->user = staffUser(Role::ContentManager);
});

describe('review defaults', function () {
    beforeEach(function () {
        $this->category = Category::factory()->create();
        $this->width = Attribute::factory()->number('mm')->create(['code' => 'width']);
        $this->weight = Attribute::factory()->number('g')->create(['code' => 'weight']);
        $this->depth = Attribute::factory()->number('mm')->create(['code' => 'depth']);
        foreach ([$this->width, $this->weight, $this->depth] as $sort => $attribute) {
            $this->category->attributes()->attach($attribute, ['sort' => $sort + 1]);
        }

        $this->product = Product::factory()->inCategory($this->category)->create([
            'is_active' => true,
            'name' => ['uz' => 'Eski nom', 'ru' => 'Старое имя', 'en' => 'Old name'],
            'short_description' => ['uz' => '', 'ru' => '', 'en' => ''],
        ]);
        $this->product->attributeValues()->create(['attribute_id' => $this->depth->id, 'value_number' => 55]);
        $specs = array_values(app(AiSpecParser::class)->parse([
            ['code' => 'width', 'value' => '120', 'text' => [], 'unsure' => false],
            ['code' => 'weight', 'value' => '250', 'text' => [], 'unsure' => true],
            ['code' => 'depth', 'value' => '60', 'text' => [], 'unsure' => false],
        ], $this->category->id)['specs']);
        $this->draft = AiProductDraft::factory()->forUser($this->user)->improving($this->product)->withFacts(wizFacts())->withProposals(wizTexts(), $specs)->create();
    });

    it('opens on the review with only the empty fields and the sure, empty specs ticked', function () {
        Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $this->product])
            ->assertSet('step', 'review')
            ->assertSet('onlyEmpty', true)
            ->assertSet('accept.name', false)
            ->assertSet('accept.short_description', true)
            ->assertSet('accept.description', true)
            ->assertSet('acceptSpecs.'.$this->width->id, true)
            // Unsure: never pre-ticked.
            ->assertSet('acceptSpecs.'.$this->weight->id, false)
            // Already has a value: kept under "only empty".
            ->assertSet('acceptSpecs.'.$this->depth->id, false);
    });

    it('ticks the filled fields too once "only empty fields" is switched off, but still not the unsure spec', function () {
        Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $this->product])
            ->set('onlyEmpty', false)
            ->assertSet('accept.name', true)
            ->assertSet('acceptSpecs.'.$this->depth->id, true)
            ->assertSet('acceptSpecs.'.$this->weight->id, false);
    });

    it('applies exactly what is ticked, keeps the product live and shows the live notice before applying', function () {
        $component = Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $this->product])
            ->set('acceptSpecs.'.$this->weight->id, true)
            ->call('toConfirm')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->assertSeeHtml('data-live');

        $component->call('apply')->assertHasNoErrors()->assertRedirect();

        $product = $this->product->refresh();
        $values = $product->attributeValues()->pluck('value_number', 'attribute_id')->map(fn ($value): float => (float) $value)->all();

        expect($product->is_active)->toBeTrue()
            ->and($product->getTranslation('name', 'uz'))->toBe('Eski nom')
            ->and($product->getTranslation('short_description', 'uz'))->toBe('Ishonchli 2D skaner.')
            ->and($values)->toBe([$this->width->id => 120.0, $this->weight->id => 250.0, $this->depth->id => 55.0]);
    });

    it('does not show the live notice for an inactive product', function () {
        $this->product->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $this->product])->call('toConfirm')->assertSet('step', 'confirm')->assertDontSeeHtml('data-live');
    });

    it('keeps an unticked field out of the apply even if the client changes its proposal text', function () {
        Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $this->product])
            ->set('texts.name.uz', 'Hacked name')
            ->call('toConfirm')->call('apply')->assertRedirect();

        expect($this->product->refresh()->getTranslation('name', 'uz'))->toBe('Eski nom');
    });

    it('shows the validation error and writes nothing when a ticked text breaks the brand rule', function () {
        Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $this->product])
            ->set('texts.short_description.ru', 'Скидка 20% только сегодня')
            ->call('toConfirm')->assertHasErrors(['texts.short_description.ru'])->assertSet('step', 'review');

        expect($this->product->refresh()->getTranslation('short_description', 'uz'))->toBe('');
    });
});

describe('stage A only for new photos', function () {
    it('skips the vision stage and the model when nothing new was added, taking the facts from the product', function () {
        $product = Product::factory()->create(['sku' => 'ZB-9']);
        $component = Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $product])
            ->call('saveInfo')->assertHasNoErrors()->call('acknowledgePhotoHint')
            ->call('look')->assertSet('step', 'facts');

        $draft = AiProductDraft::query()->sole();

        expect($draft->status)->toBe(AiDraftStatus::FactsReady)
            ->and($draft->facts['part_numbers'])->toBe(['ZB-9'])
            ->and($this->fake->calls())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('queues the vision stage when a new photo was added', function () {
        $product = Product::factory()->create(['sku' => 'ZB-10']);
        Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $product])
            ->call('saveInfo')->call('acknowledgePhotoHint')
            ->set('upload', UploadedFile::fake()->image('new.jpg', 800, 600))->assertHasNoErrors()
            ->call('look')->assertSet('step', 'facts');

        Queue::assertPushed(RunAiDraftStage::class, fn (RunAiDraftStage $job): bool => $job->stage === 'vision');
        expect(AiProductDraft::query()->sole()->status)->toBe(AiDraftStatus::Seeing);
    });

    it('does not queue the vision stage for a new photo that was removed again', function () {
        $product = Product::factory()->create(['sku' => 'ZB-11']);
        Livewire::actingAs($this->user)->test(AiWizard::class, ['product' => $product])
            ->call('saveInfo')->call('acknowledgePhotoHint')
            ->set('upload', UploadedFile::fake()->image('new.jpg', 800, 600))->call('toggleRemove', 0)
            ->call('look');

        Queue::assertNothingPushed();
    });
});
