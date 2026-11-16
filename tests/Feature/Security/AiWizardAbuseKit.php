<?php

use App\Actions\Catalog\AddProductImages;
use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Livewire\Admin\Products\AiWizard;
use App\Models\AiProductDraft;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../Admin/Catalog/AiWizard/AiWizardHelpers.php';

/**
 * Shared helpers of the Plan 32 AI product wizard abuse tests
 * (AiWizardAbuseTest, AiWizardAbuseRecheckTest, AiWizardFixRoundTest). Not a
 * test file: each test file pulls it in with require_once, which also loads
 * the wizard test helpers (wizOn, wizStorage, wizDraft, wizRun, ...). Fake
 * key only, the chat model is always a fake, the queue is faked: no call and
 * no network is possible.
 */

/**
 * The wizard of $user with a saved draft on the photo step and the photo hint acknowledged.
 */
function abzOpen(User $user, ?Product $product = null): Testable
{
    $component = $product === null
        ? Livewire::actingAs($user)->test(AiWizard::class)->set('info.category_id', (string) Category::factory()->create()->id)->set('info.sku', 'abz-'.fake()->unique()->numerify('#####'))->set('info.type', 'hardware')
        : Livewire::actingAs($user)->test(AiWizard::class, ['product' => $product]);

    return $component->call('saveInfo')->assertHasNoErrors()->call('acknowledgePhotoHint');
}

/**
 * A fresh component on the user's latest open draft (the wizard resumes it), photo hint acknowledged.
 */
function abzResume(User $user, ?Product $product = null): Testable
{
    $component = $product === null
        ? Livewire::actingAs($user)->test(AiWizard::class)
        : Livewire::actingAs($user)->test(AiWizard::class, ['product' => $product]);

    return $component->call('acknowledgePhotoHint');
}

/**
 * Every public wizard action a client can call, with harmless arguments.
 *
 * @return array<string, array{0: string, 1: list<mixed>}>
 */
function abzMethods(): array
{
    return [
        'saveInfo' => ['saveInfo', []],
        'acknowledgePhotoHint' => ['acknowledgePhotoHint', []],
        'toggleRemove' => ['toggleRemove', [0]],
        'toggleClean' => ['toggleClean', [0]],
        'setReplace' => ['setReplace', [0, null]],
        'setMain' => ['setMain', [0]],
        'look' => ['look', []],
        'write' => ['write', []],
        'poll' => ['poll', []],
        'cancel' => ['cancel', []],
        'startOver' => ['startOver', []],
        'goTo' => ['goTo', ['info']],
        'updatedOnlyEmpty' => ['updatedOnlyEmpty', []],
        'toConfirm' => ['toConfirm', []],
        'apply' => ['apply', []],
        '_startUpload' => ['_startUpload', ['upload', [['name' => 'a.jpg', 'size' => 100, 'type' => 'image/jpeg']], false]],
        '_finishUpload' => ['_finishUpload', ['upload', ['x'], false]],
    ];
}

/**
 * An open create draft with $photos uploads cleaned and stored on the (fake) disk.
 */
function abzPhotoDraft(User $user, int $photos = 1, ?Category $category = null): AiProductDraft
{
    $draft = AiProductDraft::factory()->forUser($user)->withInfo(wizInfo($category))->create();

    for ($i = 0; $i < $photos; $i++) {
        app(PrepareAiDraftImages::class)->addUpload($draft->refresh(), UploadedFile::fake()->image("photo{$i}.jpg", 320 + $i * 10, 240));
    }

    return $draft->refresh();
}

/**
 * A product with $images existing images.
 */
function abzProduct(int $images = 1): Product
{
    $product = Product::factory()->create();

    for ($i = 1; $i <= $images; $i++) {
        app(AddProductImages::class)->handle($product, [UploadedFile::fake()->image("existing{$i}.jpg", 500 + $i * 10, 300)]);
    }

    return $product->refresh();
}

/**
 * A ready improve draft of the product (snapshot taken now, as the wizard opens).
 *
 * @param  array<string, array<string, string>>  $texts
 */
function abzReadyImprove(User $user, Product $product, array $texts = []): AiProductDraft
{
    return AiProductDraft::factory()->forUser($user)->improving($product)->withFacts(wizFacts())->withProposals(wizTexts($texts))->create();
}

/**
 * The files of a draft folder on the fake private disk (relative to the folder).
 *
 * @return list<string>
 */
function abzFiles(AiProductDraft $draft): array
{
    $folder = AiProductDraft::folder($draft->uuid);

    return array_values(array_map(fn (string $path): string => substr($path, strlen($folder) + 1), Storage::disk('local')->allFiles($folder)));
}

function abzFolderExists(AiProductDraft $draft): bool
{
    return Storage::disk('local')->exists(AiProductDraft::folder($draft->uuid)) || Storage::disk('local')->directoryExists(AiProductDraft::folder($draft->uuid));
}

/**
 * Put a draft into any status (forceFill, no state machine).
 */
function abzSetStatus(AiProductDraft $draft, AiDraftStatus $status, array $columns = []): AiProductDraft
{
    $draft->forceFill(['status' => $status, ...$columns])->save();

    return $draft->refresh();
}

/**
 * A staff user with the Content manager role (the wizard's everyday user).
 */
function abzCm(): User
{
    return staffUser(Role::ContentManager);
}

/**
 * Age a draft without touching updated_at through Eloquent (the stale clock).
 */
function abzAge(AiProductDraft $draft, int $seconds): void
{
    AiProductDraft::query()->whereKey($draft->id)->toBase()->update(['updated_at' => now()->subSeconds($seconds)]);
}
