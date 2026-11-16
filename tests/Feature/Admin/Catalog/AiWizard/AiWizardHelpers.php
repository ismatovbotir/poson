<?php

use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Jobs\RunAiDraftStage;
use App\Models\AiProductDraft;
use App\Models\Category;
use App\Models\ChatAiUsageDaily;
use App\Models\Setting;
use App\Models\User;
use App\Support\Chat\ChatSettings;
use App\Support\Content\AiProduct\AiFactsParser;
use App\Support\Content\AiProduct\ProductAiDraft;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ImageFixtures;

/**
 * Shared helpers of the Plan 32 AI product wizard tests (required by each
 * test file). Fake key only, no real API call is possible (AI-25).
 */
const WIZ_KEY = 'sk-ant-test-WIZARD-HELPER-000111222333';

/**
 * Switch the wizard on or off in the settings and set (or clear) the fake key.
 */
function wizOn(bool $on = true, bool $key = true): void
{
    config(['services.anthropic.key' => $key ? WIZ_KEY : '']);
    Setting::query()->updateOrCreate(['key' => ChatSettings::key('ai_admin_enabled')], ['group' => SettingsArea::Chatbot->group(), 'value' => $on]);
    ChatSettings::forget();
}

/**
 * Fake the public and local disks under a folder of this test process, so two
 * test runs at the same time never empty each other's files (Storage::fake
 * shares one folder per disk name).
 */
function wizStorage(): void
{
    foreach (['public', 'local'] as $disk) {
        $root = storage_path('framework/testing/disks/wiz-'.getmypid().'-'.$disk);
        File::deleteDirectory($root);
        File::makeDirectory($root, 0777, true);
        Storage::set($disk, Storage::build(['driver' => 'local', 'root' => $root, 'throw' => false]));
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function wizInfo(?Category $category = null, array $overrides = []): array
{
    return array_merge([
        'category_id' => (string) ($category ?? Category::factory()->create())->id,
        'brand_id' => '',
        'model' => 'DS2208',
        'sku' => 'ZB-DS2208',
        'type' => 'hardware',
        'sale_mode' => 'both',
        'price' => '',
    ], $overrides);
}

/**
 * A draft row in the given status for $user, create mode, one upload and (for facts / write stages) confirmed facts.
 */
function wizDraft(User $user, AiDraftStatus $status = AiDraftStatus::Seeing, ?Category $category = null): AiProductDraft
{
    $draft = AiProductDraft::factory()->forUser($user)->withInfo(wizInfo($category))->status($status)->create();

    if ($status === AiDraftStatus::Writing) {
        $draft->forceFill(['facts' => wizFacts(), 'result' => ['facts_confirmed' => true]])->save();
    }

    return $draft->refresh();
}

/**
 * Put a vision copy on the (fake) local disk, as PrepareAiDraftImages would.
 */
function wizVisionFile(AiProductDraft $draft, int $number = 1): string
{
    $path = Storage::disk('local')->path(AiProductDraft::folder($draft->uuid)."/vision/{$number}.jpg");
    ImageFixtures::bordered($path, 90, 60, 30);

    return $path;
}

function wizCleanFile(AiProductDraft $draft, string $name = 'u1-clean.jpg'): string
{
    $path = Storage::disk('local')->path(AiProductDraft::folder($draft->uuid)."/clean/{$name}");
    ImageFixtures::bordered($path, 90, 60, 30);

    return $path;
}

/**
 * Seeing drafts need the vision_map the start action stores.
 */
function wizSeeing(User $user, int $images = 1): AiProductDraft
{
    $draft = wizDraft($user, AiDraftStatus::Seeing);
    $uploads = [];

    for ($n = 1; $n <= $images; $n++) {
        $uploads[] = ['source' => 'upload', 'ref' => $n, 'clean' => true, 'action' => 'add', 'replaces' => null];
        wizVisionFile($draft, $n);
    }

    $draft->forceFill(['input' => [...$draft->input, 'images' => $uploads, 'vision_map' => range(0, max(0, $images - 1)), 'next_upload' => $images + 1]])->save();

    return $draft->refresh();
}

/**
 * Facts as the confirm step stores them.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wizFacts(array $overrides = [], int $images = 1): array
{
    return array_merge(AiFactsParser::blank($images), ['device_type' => 'Barcode scanner', 'brand' => 'Zebra', 'models' => ['DS2208'], 'features' => ['1D/2D imager']], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wizFactsAnswer(array $overrides = [], int $images = 1): string
{
    return (string) json_encode(wizFacts($overrides, $images));
}

/**
 * A valid stage B answer; $overrides replace whole top-level keys.
 *
 * @param  array<string, mixed>  $overrides
 */
function wizWriteAnswer(array $overrides = []): string
{
    $texts = fn (string $uz, string $ru, string $en): array => ['uz' => $uz, 'ru' => $ru, 'en' => $en];

    return (string) json_encode(array_merge([
        'name' => $texts('Zebra DS2208 shtrix-kod skaneri', 'Сканер штрихкодов Zebra DS2208', 'Zebra DS2208 barcode scanner'),
        'short_description' => $texts('Ishonchli 2D skaner.', 'Надёжный 2D-сканер.', 'A reliable 2D scanner.'),
        'description' => $texts('<p>Skaner doʻkon kassalari uchun.</p>', '<p>Сканер для касс.</p>', '<p>A scanner for checkouts.</p>'),
        'meta_title' => $texts('Zebra DS2208', 'Zebra DS2208', 'Zebra DS2208'),
        'meta_description' => $texts('Zebra DS2208 skaneri.', 'Сканер Zebra DS2208.', 'Zebra DS2208 scanner.'),
        'specs' => [],
        'alts' => [['index' => 1, 'uz' => 'Zebra DS2208 old tomondan', 'ru' => 'Zebra DS2208 спереди', 'en' => 'Zebra DS2208 front']],
    ], $overrides));
}

/**
 * Run a stage job by hand (the queue is faked in the tests that start stages).
 */
function wizRun(AiProductDraft $draft, string $stage): void
{
    app()->call([new RunAiDraftStage($draft->id, $stage), 'handle']);
}

/**
 * Take ai.generate away from the Content manager role (the cache is cleared).
 */
function wizRevokeAiGenerate(): void
{
    Spatie\Permission\Models\Role::findByName(Role::ContentManager->value)->revokePermissionTo('ai.generate');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function wizUsage(string $channel): ?ChatAiUsageDaily
{
    return ChatAiUsageDaily::query()->where('channel', $channel)->first();
}

/**
 * The texts the stage B answer parses to (field => locale => text), for ready drafts.
 *
 * @return array<string, array<string, string>>
 */
function wizTexts(array $overrides = []): array
{
    return array_merge([
        'name' => ['uz' => 'Zebra DS2208 shtrix-kod skaneri', 'ru' => 'Сканер штрихкодов Zebra DS2208', 'en' => 'Zebra DS2208 barcode scanner'],
        'short_description' => ['uz' => 'Ishonchli 2D skaner.', 'ru' => 'Надёжный 2D-сканер.', 'en' => 'A reliable 2D scanner.'],
        'description' => ['uz' => '<p>Skaner doʻkon kassalari uchun.</p>', 'ru' => '<p>Сканер для касс.</p>', 'en' => '<p>A scanner for checkouts.</p>'],
        'meta_title' => ['uz' => 'Zebra DS2208', 'ru' => 'Zebra DS2208', 'en' => 'Zebra DS2208'],
        'meta_description' => ['uz' => 'Zebra DS2208 skaneri.', 'ru' => 'Сканер Zebra DS2208.', 'en' => 'Zebra DS2208 scanner.'],
    ], $overrides);
}

/**
 * A create draft in "ready" with $photos uploads processed (plain + clean files on the fake disk).
 *
 * @param  list<array<string, mixed>>  $specs  validated spec proposals (AiSpecParser rows)
 */
function wizReadyCreate(User $user, ?Category $category = null, int $photos = 1, array $texts = [], array $specs = [], array $alts = [0 => ['uz' => 'Zebra old', 'ru' => 'Zebra спереди', 'en' => 'Zebra front']]): AiProductDraft
{
    $draft = AiProductDraft::factory()->forUser($user)->withInfo(wizInfo($category))->withFacts(wizFacts())->withProposals(wizTexts($texts), $specs, $alts)->create();

    for ($i = 0; $i < $photos; $i++) {
        app(PrepareAiDraftImages::class)->addUpload($draft->refresh(), UploadedFile::fake()->image("photo{$i}.jpg", 600 + $i * 100, 400));
    }

    return $draft->refresh();
}

/**
 * The wizard's hand-over for a draft, as Livewire state would build it.
 *
 * @param  array<string, mixed>  $overrides
 */
function wizData(AiProductDraft $draft, array $overrides = []): ProductAiDraft
{
    $images = [];

    foreach (array_values((array) ($draft->input['images'] ?? [])) as $index => $image) {
        $images[$index] = ['clean' => (bool) $image['clean'], 'action' => (string) $image['action'], 'replaces' => $image['replaces'] ?? null, 'confirmed' => false];
    }

    return ProductAiDraft::fromArray(array_merge([
        'mode' => $draft->mode,
        'product_id' => $draft->product_id,
        'info' => $draft->input['info'] ?? [],
        'texts' => $draft->result['texts'] ?? [],
        'specs' => $draft->result['specs'] ?? [],
        'images' => $images,
        'alts' => $draft->result['alts'] ?? [],
        'main' => $images === [] ? null : 0,
        'accepted' => $draft->isImprove() ? [] : [...ProductAiDraft::TEXT_FIELDS, 'alts', 'images'],
    ], $overrides));
}
