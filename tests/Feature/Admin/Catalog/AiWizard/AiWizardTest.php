<?php

use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\ActivityType;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Jobs\RunAiDraftStage;
use App\Livewire\Admin\Products\AiWizard;
use App\Livewire\Admin\Products\Index;
use App\Models\Activity;
use App\Models\AiProductDraft;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeChatModel;

/**
 * Plan 32 task 6 smoke tests: the AI product wizard happy path (create),
 * authorization per role, the gate-off state and draft ownership. Fake key
 * and FakeChatModel only (AI-25); the stage jobs run by hand after Queue::fake.
 */
const AI_WIZARD_KEY = 'sk-ant-test-WIZARD-000111222333444555';

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

function aiWizardOn(bool $on = true, bool $key = true): void
{
    config(['services.anthropic.key' => $key ? AI_WIZARD_KEY : '']);
    Setting::query()->updateOrCreate(['key' => ChatSettings::key('ai_admin_enabled')], ['group' => SettingsArea::Chatbot->group(), 'value' => $on]);
    ChatSettings::forget();
}

function runAiStage(AiProductDraft $draft, string $stage): void
{
    app()->call([new RunAiDraftStage($draft->id, $stage), 'handle']);
}

function aiFactsAnswer(): string
{
    return (string) json_encode([
        'device_type' => 'Barcode scanner',
        'brand' => 'Zebra',
        'models' => ['DS2208'],
        'part_numbers' => [],
        'features' => ['1D/2D imager'],
        'accessories' => [],
        'views' => [['index' => 1, 'note' => 'Front view', 'warnings' => []]],
        'main_image' => 1,
        'unsure' => [],
    ]);
}

function aiWriteAnswer(): string
{
    $texts = fn (string $uz, string $ru, string $en): array => ['uz' => $uz, 'ru' => $ru, 'en' => $en];

    return (string) json_encode([
        'name' => $texts('Zebra DS2208 shtrix-kod skaneri', 'Сканер штрихкодов Zebra DS2208', 'Zebra DS2208 barcode scanner'),
        'short_description' => $texts('Ishonchli 2D skaner.', 'Надёжный 2D-сканер.', 'A reliable 2D scanner.'),
        'description' => $texts('<p>Skaner doʻkon kassalari uchun.</p>', '<p>Сканер для касс.</p>', '<p>A scanner for checkouts.</p>'),
        'meta_title' => $texts('Zebra DS2208', 'Zebra DS2208', 'Zebra DS2208'),
        'meta_description' => $texts('Zebra DS2208 skaneri.', 'Сканер Zebra DS2208.', 'Zebra DS2208 scanner.'),
        'specs' => [],
        'alts' => [['index' => 1, 'uz' => 'Zebra DS2208 old tomondan', 'ru' => 'Zebra DS2208 спереди', 'en' => 'Zebra DS2208 front']],
    ]);
}

it('hides the wizard and refuses its routes while the switch is off or the key is missing', function (bool $on, bool $key) {
    aiWizardOn($on, $key);
    $admin = staffUser(Role::Admin);
    $product = Product::factory()->create();

    Livewire::actingAs($admin)->test(Index::class)->assertDontSeeHtml('data-ai-create')->assertDontSeeHtml('data-ai-improve');

    $this->actingAs($admin)->get(route('admin.products.ai.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.products.ai.improve', $product))->assertForbidden();
})->with([
    'switch off' => [false, true],
    'key missing' => [true, false],
]);

it('opens the wizard for Admin and Content manager', function (Role $role) {
    aiWizardOn();
    $user = staffUser($role);
    $product = Product::factory()->create();

    Livewire::actingAs($user)->test(Index::class)->assertSeeHtml('data-ai-create')->assertSeeHtml('data-ai-improve');

    $this->actingAs($user)->get(route('admin.products.ai.create'))->assertOk()->assertSeeLivewire(AiWizard::class);
    $this->actingAs($user)->get(route('admin.products.ai.improve', $product))->assertOk();
})->with(catalogWriterRoles());

it('refuses the wizard to sales managers and service engineers', function (Role $role) {
    aiWizardOn();
    $user = staffUser($role);
    $product = Product::factory()->create();

    $this->actingAs($user)->get(route('admin.products.ai.create'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.products.ai.improve', $product))->assertForbidden();
})->with(catalogViewOnlyRoles());

it('refuses a content manager without ai.generate on every request', function () {
    aiWizardOn();
    $user = staffUser(Role::ContentManager);
    $component = Livewire::actingAs($user)->test(AiWizard::class);

    $user->roles->first()->revokePermissionTo('ai.generate');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('saveInfo')->assertForbidden();
});

it('returns 404 for the draft of another user', function () {
    aiWizardOn();
    $draft = AiProductDraft::factory()->create(['user_id' => staffUser(Role::ContentManager)->id, 'mode' => AiProductDraft::CREATE, 'product_id' => null]);

    $this->actingAs(staffUser(Role::Admin))->get(route('admin.products.ai.create', ['draft' => $draft->uuid]))->assertNotFound();
});

it('creates an inactive product from photos, confirmed facts and written texts', function () {
    aiWizardOn();
    Queue::fake();
    $fake = FakeChatModel::bind(aiFactsAnswer(), aiWriteAnswer());
    $user = staffUser(Role::ContentManager);
    $category = Category::factory()->create();

    $component = Livewire::actingAs($user)->test(AiWizard::class)
        ->set('info.category_id', (string) $category->id)
        ->set('info.sku', 'zb-ds2208')
        ->set('info.type', 'hardware')
        ->call('saveInfo')
        ->assertHasNoErrors()
        ->assertSet('step', 'photos')
        ->call('acknowledgePhotoHint')
        ->set('upload', UploadedFile::fake()->image('scanner.jpg', 900, 700))
        ->assertHasNoErrors();

    $draft = AiProductDraft::query()->where('user_id', $user->id)->sole();
    expect($draft->input['images'])->toHaveCount(1)
        ->and($draft->input['info']['sku'])->toBe('ZB-DS2208');

    $component->call('look')->assertSet('step', 'facts');
    Queue::assertPushed(RunAiDraftStage::class, fn (RunAiDraftStage $job): bool => $job->stage === RunAiDraftStage::VISION && $job->draftId === $draft->id);

    runAiStage($draft, RunAiDraftStage::VISION);
    expect($draft->refresh()->status)->toBe(AiDraftStatus::FactsReady)
        ->and($fake->last()->images)->toHaveCount(1);

    $component->call('poll')->assertSet('facts.brand', 'Zebra')
        ->call('write')->assertHasNoErrors()->assertSet('step', 'write');

    runAiStage($draft, RunAiDraftStage::WRITE);
    expect($draft->refresh()->status)->toBe(AiDraftStatus::Ready)
        ->and($fake->last()->images)->toBe([]);

    $component->call('poll')->assertSet('step', 'review')
        ->call('toConfirm')->assertHasNoErrors()->assertSet('step', 'confirm')
        ->call('apply')->assertHasNoErrors()->assertRedirect();

    $product = Product::query()->where('sku', 'ZB-DS2208')->sole();

    expect($product->is_active)->toBeFalse()
        ->and($product->is_featured)->toBeFalse()
        ->and($product->getTranslation('name', 'en'))->toBe('Zebra DS2208 barcode scanner')
        ->and($product->getMedia(Product::IMAGES))->toHaveCount(1)
        ->and($product->getMedia(Product::IMAGES)->first()->getCustomProperty('alt')['en'])->toBe('Zebra DS2208 front')
        ->and($draft->refresh()->status)->toBe(AiDraftStatus::Applied)
        ->and($fake->calls())->toBe(2);

    $activity = Activity::query()->where('subject_id', $product->id)->where('type', ActivityType::AiCreate)->sole();
    expect($activity->body)->not->toContain('Zebra');

    // Idempotent: a second apply returns the same product, no second image set.
    $component->call('apply');
    expect(Product::query()->where('sku', 'ZB-DS2208')->count())->toBe(1)
        ->and($product->fresh()->getMedia(Product::IMAGES))->toHaveCount(1);
});

it('improves only the accepted empty fields of an existing product and keeps its publish state', function () {
    aiWizardOn();
    Queue::fake();
    FakeChatModel::bind(aiWriteAnswer());
    $user = staffUser(Role::ContentManager);
    $product = Product::factory()->create([
        'sku' => 'ZB-1',
        'is_active' => true,
        'name' => ['uz' => 'Eski nom', 'ru' => 'Старое имя', 'en' => 'Old name'],
        'short_description' => ['uz' => '', 'ru' => '', 'en' => ''],
    ]);
    $slug = $product->slug;

    $component = Livewire::actingAs($user)->test(AiWizard::class, ['product' => $product])
        ->call('saveInfo')->assertHasNoErrors()->assertSet('step', 'photos')
        ->call('acknowledgePhotoHint')
        // No new photos: stage A is skipped, the facts come from the product.
        ->call('look')->assertSet('step', 'facts');

    Queue::assertNothingPushed();

    $draft = AiProductDraft::query()->where('product_id', $product->id)->sole();
    $component->call('write')->assertSet('step', 'write');
    runAiStage($draft, RunAiDraftStage::WRITE);

    $component->call('poll')->assertSet('step', 'review')
        ->assertSet('accept.name', false)
        ->assertSet('accept.short_description', true)
        ->assertDontSee('ai-drafts')
        ->call('toConfirm')->assertHasNoErrors()
        ->call('apply')->assertHasNoErrors()->assertRedirect();

    $product->refresh();

    expect($product->getTranslation('name', 'en'))->toBe('Old name')
        ->and($product->getTranslation('short_description', 'en'))->toBe('A reliable 2D scanner.')
        ->and($product->is_active)->toBeTrue()
        ->and($product->slug)->toBe($slug)
        ->and(Activity::query()->where('subject_id', $product->id)->where('type', ActivityType::AiImprove)->count())->toBe(1);
});

it('refuses to apply when an accepted field changed since the wizard opened', function () {
    aiWizardOn();
    Queue::fake();
    FakeChatModel::bind(aiWriteAnswer());
    $user = staffUser(Role::ContentManager);
    $product = Product::factory()->create(['short_description' => ['uz' => '', 'ru' => '', 'en' => '']]);

    $component = Livewire::actingAs($user)->test(AiWizard::class, ['product' => $product])
        ->call('saveInfo')->call('acknowledgePhotoHint')->call('look')->call('write');
    runAiStage(AiProductDraft::query()->where('product_id', $product->id)->sole(), RunAiDraftStage::WRITE);
    $component->call('poll')->call('toConfirm');

    // Someone else fills the short description meanwhile.
    $product->setTranslation('short_description', 'uz', 'Boshqa matn')->save();

    $component->call('apply')->assertHasErrors('draft')->assertNoRedirect();

    expect($product->refresh()->getTranslation('short_description', 'uz'))->toBe('Boshqa matn');
});

it('lets only an Admin switch the wizard, and only with a key', function () {
    config(['services.anthropic.key' => '']);
    $admin = staffUser(Role::Admin);
    $save = app(SaveChatbotSettings::class);

    expect(fn () => $save->handle(['ai_admin_enabled' => true], $admin))->toThrow(ValidationException::class);

    config(['services.anthropic.key' => AI_WIZARD_KEY]);
    expect($save->handle(['ai_admin_enabled' => true], $admin))->toBe(1);
    ChatSettings::forget();
    expect(AiSwitch::adminEnabled())->toBeTrue();

    $manager = staffUser(Role::ContentManager);
    expect(fn () => $save->handle(['ai_admin_enabled' => false], $manager))->toThrow(AuthorizationException::class)
        ->and(fn () => $save->handle(['ai_adm_write_daily_requests' => 5], $manager))->toThrow(AuthorizationException::class);
});
