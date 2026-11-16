<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Catalog\Ai\AiDraftContext;
use App\Actions\Catalog\Ai\AiDraftLock;
use App\Actions\Catalog\Ai\AiDraftProposal;
use App\Actions\Catalog\Ai\AiDraftSnapshot;
use App\Actions\Catalog\Ai\AiDraftState;
use App\Actions\Catalog\Ai\AiWizardGate;
use App\Actions\Catalog\Ai\ApplyAiDraftToProduct;
use App\Actions\Catalog\Ai\ConfirmAiDraftFacts;
use App\Actions\Catalog\Ai\CreateProductFromAiDraft;
use App\Actions\Catalog\Ai\DiscardAiDraft;
use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Actions\Catalog\Ai\SaveAiDraftInfo;
use App\Actions\Catalog\Ai\StartAiDraftStage;
use App\Actions\Catalog\Ai\UpdateAiDraftImage;
use App\Enums\AiDraftStatus;
use App\Enums\AttributeType;
use App\Enums\ProductType;
use App\Enums\SaleMode;
use App\Jobs\RunAiDraftStage;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\AiProductDraft;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductFormData;
use App\Support\Content\AiProduct\AiFactsParser;
use App\Support\Content\AiProduct\ProductAiDraft;
use App\Support\Media\AdminMediaUrl;
use App\Support\Media\ImageLimits;
use App\Support\Media\ImageRejected;
use App\Support\Slug;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * AI product wizard (plan 32 §5), full page, two modes:
 *  - create (admin.products.ai.create): main info → photos → stage A "look at
 *    the photos" (Haiku, queued) → the user confirms / edits the facts →
 *    stage B "write" (Sonnet, queued, no images) → review → confirm → a NEW
 *    INACTIVE product (CreateProductFromAiDraft);
 *  - improve (admin.products.ai.improve): the same on an existing product,
 *    stage A only for newly added photos, review as a per-field diff current →
 *    proposed with accept toggles ("only empty fields" on by default),
 *    ApplyAiDraftToProduct.
 *
 * Security (PW-6, PW-17, PW-23, PW-29): mode, product id, draft uuid and the
 * step are #[Locked] and server-set; every request re-runs authorizeWizard()
 * (hydrate, every action, uploads, the poll): ai.generate + the Admin switch
 * with a key (AiWizardGate) + create / update on the FRESH product + useType.
 * The draft is always read by uuid + user (another user's uuid is a 404).
 * Image refs never leave the server: the client names an image by its index.
 * Previews are data-URIs computed in render(), never properties or URLs to
 * ai-drafts. The poll runs only while a stage is pending. Rate limits are hit
 * inside StartAiDraftStage, called from these methods. All writes go through
 * actions.
 */
#[Layout('layouts::admin')]
class AiWizard extends Component
{
    use HandlesActionErrors;
    use WithFileUploads {
        _startUpload as protected startTemporaryUpload;
        _finishUpload as protected finishTemporaryUpload;
    }

    public const STEPS = ['info', 'photos', 'facts', 'write', 'review', 'confirm'];

    public const LIST_FACTS = ['models', 'part_numbers', 'features', 'accessories'];

    #[Locked]
    public string $mode = AiProductDraft::CREATE;

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public ?string $uuid = null;

    #[Locked]
    public string $step = 'info';

    /**
     * Step 1 (the product form's values as strings; price in whole soʻm).
     *
     * @var array{category_id: string, brand_id: string, model: string, sku: string, type: string, sale_mode: string, price: string}
     */
    public $info = ['category_id' => '', 'brand_id' => '', 'model' => '', 'sku' => '', 'type' => '', 'sale_mode' => 'both', 'price' => ''];

    /**
     * @var string
     */
    public $notes = '';

    /**
     * The one new photo of this request (one decode per Livewire call, PW-1).
     *
     * @var TemporaryUploadedFile|null
     */
    public $upload = null;

    /**
     * Image flags by draft index, a mirror of the draft row (changed only through methods).
     *
     * @var array<int, array{source: string, clean: bool, action: string, replaces: int|null}>
     */
    #[Locked]
    public array $images = [];

    #[Locked]
    public ?int $mainImage = null;

    /**
     * Improve: the step 1 values and the field snapshot when the page opened
     * (L10, PW-21), the baseline of the apply conflict check.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $opened = [];

    /**
     * @var array<string, string>
     */
    #[Locked]
    public array $openedSnapshot = [];

    /**
     * Explicit confirmation of replacing / removing an existing image (PW-22), by draft index.
     *
     * @var array<int|string, bool>
     */
    public $confirmed = [];

    /**
     * Facts being checked (3a): strings, lists one item per line, view notes by image number.
     *
     * @var array<string, mixed>
     */
    public $facts = [];

    /**
     * Proposals: field → locale → text.
     *
     * @var array<string, array<string, string>>
     */
    public $texts = [];

    /**
     * Spec proposals by attribute id (AiSpecParser rows).
     *
     * @var array<int|string, array<string, mixed>>
     */
    public $specs = [];

    /**
     * Alt texts: draft image index → locale → text.
     *
     * @var array<int|string, array<string, string>>
     */
    public $alts = [];

    /**
     * Accept toggles: text field keys, "alts", "images".
     *
     * @var array<string, bool>
     */
    public $accept = [];

    /**
     * Accept toggles of the spec proposals, by attribute id ("spec:{id}" in the DTO).
     *
     * @var array<int|string, bool>
     */
    public $acceptSpecs = [];

    /**
     * @var bool
     */
    public $onlyEmpty = true;

    /**
     * Version of the photo hint text: a changed hint asks every user again (L5, PW-23).
     */
    private const PHOTO_HINT_VERSION = 'v1';

    /**
     * Root properties the client may update, with the shape they must have (F5).
     */
    private const SHAPES = [
        'info' => 'strings', 'notes' => 'string', 'confirmed' => 'flags', 'facts' => 'facts',
        'texts' => 'texts', 'specs' => 'specs', 'alts' => 'texts', 'accept' => 'flags',
        'acceptSpecs' => 'flags', 'onlyEmpty' => 'bool', 'upload' => 'upload',
    ];

    /**
     * Memo of authorizeWizard() for this request (F6): key → product.
     *
     * @var array{key: string, product: Product|null}|null
     */
    private ?array $gate = null;

    /**
     * @var list<ProductType>|null
     */
    private ?array $types = null;

    public function mount(?Product $product = null): void
    {
        $user = auth()->user();

        if ($product?->exists) {
            abort_unless(AiWizardGate::allowsImprove($user, $product), 403);

            $this->mode = AiProductDraft::IMPROVE;
            $this->productId = $product->id;
            $this->info = SaveAiDraftInfo::baseInfo($product);
            $this->opened = $this->info;
            $this->openedSnapshot = AiDraftSnapshot::of($product);
        } else {
            abort_unless(AiWizardGate::allowsCreate($user), 403);

            $this->info['type'] = ($this->typeOptions()[0] ?? ProductType::Hardware)->value;
        }

        $requested = request()->query('draft');
        $draft = null;

        if ($requested !== null) {
            $draft = is_string($requested) && Str::isUuid($requested) ? $this->ownedDraft($requested) : null;

            abort_if($draft === null || $draft->status->isFinal(), 404);
        } else {
            $draft = AiProductDraft::query()
                ->where('user_id', $user->id)
                ->where('mode', $this->mode)
                // A create draft gets its product_id only when the apply begins (resume / interrupted panel, N5).
                ->when($this->mode === AiProductDraft::IMPROVE, fn ($query) => $query->where('product_id', $this->productId))
                ->whereNotIn('status', AiDraftStatus::values([AiDraftStatus::Applied, AiDraftStatus::Expired]))
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();
        }

        if ($draft !== null) {
            $this->resume($draft);
        }
    }

    public function hydrate(): void
    {
        $this->authorizeWizard();
    }

    /**
     * Tampered payloads (an array where a string belongs, …) become a
     * validation error and a normalized state, never a TypeError / 500 (F5,
     * PW-6). The client-writable properties are untyped on purpose: Livewire
     * assigns before any hook could refuse, and authorizeWizard() normalizes
     * them on every request.
     */
    public function updated(string $name, mixed $value): void
    {
        $path = explode('.', $name);

        if (array_key_exists($path[0], self::SHAPES) && ! $this->shapeAllows(self::SHAPES[$path[0]], array_slice($path, 1), $value)) {
            $this->normalizeState();
            $this->addError($name, __('admin.ai_wizard.errors.invalid_input'));
        }
    }

    /**
     * Uploads are writes: authorized before Livewire accepts the temporary file (PW-6).
     */
    public function _startUpload($name, $fileInfo, $isMultiple)
    {
        $this->authorizeWizard();
        abort_unless($name === 'upload' && $this->uuid !== null && $this->photoHintAcknowledged(), 403);

        return $this->startTemporaryUpload($name, $fileInfo, $isMultiple);
    }

    public function _finishUpload($name, $tmpPath, $isMultiple, $append = true)
    {
        $this->authorizeWizard();
        abort_unless($name === 'upload' && $this->uuid !== null && $this->photoHintAcknowledged(), 403);

        return $this->finishTemporaryUpload($name, $tmpPath, $isMultiple, $append);
    }

    /**
     * Step 1 → photos: validate the main info like the product form, open or update the draft.
     */
    public function saveInfo(SaveAiDraftInfo $saveInfo): void
    {
        $product = $this->authorizeWizard();
        $this->info = AiDraftProposal::info($this->info);
        $rules = ProductFormData::rules($this->productId);

        $this->validate([
            'info.category_id' => $rules['category_id'],
            'info.brand_id' => $rules['brand_id'],
            'info.model' => $rules['model'],
            'info.sku' => $rules['sku'],
            'info.type' => $rules['type'],
            'info.sale_mode' => $rules['sale_mode'],
            'info.price' => $rules['price'],
            'notes' => ['nullable', 'string', 'max:'.(int) config('chatbot.ai.product.notes_max', 2000)],
        ], [], [
            'info.category_id' => __('admin.products.fields.category'),
            'info.brand_id' => __('admin.products.fields.brand'),
            'info.model' => __('admin.products.fields.model'),
            'info.sku' => __('admin.products.fields.sku'),
            'info.type' => __('admin.products.fields.type'),
            'info.sale_mode' => __('admin.products.fields.sale_mode'),
            'info.price' => __('admin.ai_wizard.info.price'),
            'notes' => __('admin.ai_wizard.info.notes'),
        ]);

        $this->authorize('useType', [Product::class, ProductType::from($this->info['type'])]);

        $draft = $this->uuid !== null ? $this->draft() : null;
        $opened = $this->mode === AiProductDraft::IMPROVE ? ['info' => $this->opened, 'snapshot' => $this->openedSnapshot] : null;
        $draft = $this->runFormAction(fn (): AiProductDraft => $saveInfo->handle(auth()->user(), $draft, $this->mode, $product, $this->info, $this->notes, $opened), '');

        $this->uuid = $draft->uuid;
        $this->syncImages($draft);
        $this->step = 'photos';
    }

    /**
     * PW-23: the one-time "no people, documents or personal data" acknowledgement.
     */
    public function acknowledgePhotoHint(): void
    {
        $this->authorizeWizard();

        Cache::forever($this->photoHintKey(), true);
    }

    public function updatedUpload(PrepareAiDraftImages $prepare): void
    {
        $this->authorizeWizard();
        $draft = $this->draft();

        if (! $this->photoHintAcknowledged() || ! $this->editable($draft)) {
            $this->resetUpload();

            return;
        }

        $this->validate([
            'upload' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('chatbot.ai.product.upload_max_kb', 5120), ImageLimits::dimensionsRule()],
        ], [], ['upload' => __('admin.image_upload.image')]);

        try {
            $index = $prepare->addUpload($draft, $this->upload);
        } catch (ImageRejected $rejected) {
            $this->addError('upload', __($rejected->messageKey()));

            return;
        } finally {
            $this->resetUpload();
        }

        $this->syncImages($draft);
        $this->mainImage ??= $index;
    }

    /**
     * Remove (or bring back) an image of the draft.
     */
    public function toggleRemove(mixed $index, UpdateAiDraftImage $update): void
    {
        $product = $this->authorizeWizard();
        $index = $this->imageIndex($index);

        if ($index === null) {
            return;
        }

        $image = $this->images[$index];
        $action = $image['action'] === 'remove' ? ($image['source'] === 'upload' ? 'add' : 'keep') : 'remove';

        $this->updateImage($update, $product, $index, ['action' => $action, 'replaces' => null]);

        if ($this->mainImage === $index && $action === 'remove') {
            $this->mainImage = null;
        }
    }

    public function toggleClean(mixed $index, UpdateAiDraftImage $update): void
    {
        $product = $this->authorizeWizard();
        $index = $this->imageIndex($index);

        if ($index !== null) {
            $this->updateImage($update, $product, $index, ['clean' => ! $this->images[$index]['clean']]);
        }
    }

    /**
     * Improve: a new photo replaces an existing one ($target = its index; empty = add instead).
     */
    public function setReplace(mixed $index, mixed $target, UpdateAiDraftImage $update): void
    {
        $product = $this->authorizeWizard();
        $index = $this->imageIndex($index);
        $target = $target === '' || $target === null ? null : $this->imageIndex($target);

        if ($index === null || $this->images[$index]['source'] !== 'upload') {
            return;
        }

        $flags = $target !== null && $this->images[$target]['source'] === 'media'
            ? ['action' => 'replace', 'replaces' => $target]
            : ['action' => 'add', 'replaces' => null];

        $this->updateImage($update, $product, $index, $flags);
    }

    public function setMain(mixed $index): void
    {
        $this->authorizeWizard();
        $index = $this->imageIndex($index);

        if ($index !== null && $this->images[$index]['action'] !== 'remove') {
            $this->mainImage = $index;
        }
    }

    /**
     * Step 2 → 3a: start stage A (or, improve without new photos, take the facts from the product).
     */
    public function look(StartAiDraftStage $start, ConfirmAiDraftFacts $confirm, AiDraftContext $context): void
    {
        $product = $this->authorizeWizard();
        $draft = $this->draft();

        if (! $this->photoHintAcknowledged()) {
            $this->addError('upload', __('admin.ai_wizard.photos.hint_required'));

            return;
        }

        $newPhotos = array_filter($this->images, fn (array $image): bool => $image['source'] === 'upload' && $image['action'] !== 'remove');

        // An interrupted / started apply is never re-opened via prefill (L1).
        if (! $this->editable($draft)) {
            $this->refusal('state');

            return;
        }

        if ($draft->isImprove() && $newPhotos === [] && $product !== null) {
            if ($confirm->prefill($draft, $context->factsFromProduct($product))) {
                $this->facts = [];
                $this->loadFacts($draft->refresh());
                $this->step = 'facts';
            } else {
                $this->refusal('state');
            }

            return;
        }

        $outcome = $start->handle(auth()->user(), $draft, RunAiDraftStage::VISION);

        if ($outcome === 'started') {
            $this->facts = [];
            $this->step = 'facts';

            return;
        }

        $this->refusal($outcome);
    }

    /**
     * 3a → 3b: confirm the (edited) facts and start stage B.
     */
    public function write(StartAiDraftStage $start, ConfirmAiDraftFacts $confirm): void
    {
        $this->authorizeWizard();
        $draft = $this->draft();

        // Stage B only from the facts step (or its retry) with facts from stage A / the product (L4).
        if (! in_array($this->step, ['facts', 'write'], true) || $draft->facts === null) {
            $this->refusal('state');

            return;
        }

        $errors = $confirm->handle($draft, $this->factsInput($draft));

        if ($errors !== []) {
            $this->addError('facts', implode(' ', array_map(fn (string $key): string => $this->factsError($key), array_slice($errors, 0, 5))));

            return;
        }

        $outcome = $start->handle(auth()->user(), $draft->refresh(), RunAiDraftStage::WRITE);

        if ($outcome === 'started') {
            $this->step = 'write';

            return;
        }

        $this->refusal($outcome);
    }

    /**
     * The status island (wire:poll.2s only while a stage is pending). A lost
     * stage (no worker, crashed job) is moved to failed here (M2), which ends
     * the poll; while nothing changed the response carries no HTML (F6).
     */
    public function poll(): void
    {
        $product = $this->authorizeWizard();

        if ($this->uuid === null) {
            $this->skipRender();

            return;
        }

        $draft = $this->draft();
        $step = $this->step;

        if (AiDraftState::isStale($draft)) {
            AiDraftState::failStale($draft->id);
            $draft->refresh();
        }

        if ($draft->status === AiDraftStatus::FactsReady && $this->step === 'facts' && $this->facts === []) {
            $this->loadFacts($draft);
        }

        if (in_array($draft->status, [AiDraftStatus::Ready, AiDraftStatus::Truncated], true) && $this->step === 'write') {
            $this->loadProposals($draft, $product);
            $this->step = 'review';
        }

        if ($draft->status->isPending() && $step === $this->step) {
            $this->skipRender();
        }
    }

    /**
     * Cancel the running stage and discard the draft.
     */
    public function cancel(DiscardAiDraft $discard): void
    {
        $this->authorizeWizard();

        if ($this->uuid !== null && ! $discard->handle($this->draft())) {
            $this->refusal('state');

            return;
        }

        session()->flash('toast', ['title' => __('admin.toast.done'), 'body' => __('admin.ai_wizard.discarded'), 'type' => 'success']);
        $this->redirectRoute($this->productId !== null ? 'admin.products.edit' : 'admin.products.index', $this->productId !== null ? ['product' => $this->productId] : [], navigate: true);
    }

    public function startOver(DiscardAiDraft $discard): void
    {
        $this->authorizeWizard();

        if ($this->uuid !== null && ! $discard->handle($this->draft())) {
            $this->refusal('state');

            return;
        }

        $this->redirectRoute(...$this->wizardRoute(), navigate: true);
    }

    public function goTo(mixed $step): void
    {
        $this->authorizeWizard();

        if (! is_string($step) || ! in_array($step, self::STEPS, true) || $this->uuid === null) {
            return;
        }

        $draft = $this->draft();
        $target = array_search($step, self::STEPS, true);

        if ($draft->status->isPending() || $draft->status === AiDraftStatus::Applying || AiDraftLock::applyStarted($draft) || $target >= array_search($this->step, self::STEPS, true)) {
            return;
        }

        if ($step === 'facts' && $draft->facts === null) {
            return;
        }

        if (in_array($step, ['write'], true)) {
            $step = 'facts';
        }

        $this->step = $step;
    }

    /**
     * "Only empty fields" (improve): reset the accept toggles to their defaults.
     */
    public function updatedOnlyEmpty(): void
    {
        $product = $this->authorizeWizard();

        [$this->accept, $this->acceptSpecs] = $this->defaultAccept($product);
    }

    /**
     * Review → confirm: the texts are checked like at apply (PW-14).
     */
    public function toConfirm(AiDraftProposal $proposal): void
    {
        $this->authorizeWizard();
        $draft = $this->draft();

        if (! in_array($draft->status, [AiDraftStatus::Ready, AiDraftStatus::Truncated], true)) {
            $this->refusal('state');

            return;
        }

        $fields = $this->mode === AiProductDraft::CREATE
            ? ProductAiDraft::TEXT_FIELDS
            : array_values(array_filter(ProductAiDraft::TEXT_FIELDS, fn (string $field): bool => $this->accept[$field] ?? false));

        $texts = $this->runFormAction(fn (): array => $proposal->texts($this->texts, $fields), '');

        if ($this->mode === AiProductDraft::CREATE && trim(strip_tags($texts['name']['uz'] ?? '')) === '') {
            throw ValidationException::withMessages(['texts.name.uz' => __('validation.required', ['attribute' => __('admin.catalog.name').' (UZ)'])]);
        }

        if ($this->mode === AiProductDraft::CREATE || ($this->accept['alts'] ?? false)) {
            $this->runFormAction(fn (): array => $proposal->alts($this->altsInput(), count($this->images)), '');
        }

        foreach ($fields as $field) {
            foreach (config('app.supported_locales') as $locale) {
                $this->texts[$field][$locale] = $texts[$field][$locale] ?? '';
            }
        }

        $this->step = 'confirm';
    }

    /**
     * Confirm → product. Also "finish applying" for a draft left in applying
     * (F3): the actions return the product of an applying draft and resume
     * the images from apply_state (no image is added twice, L7).
     */
    public function apply(CreateProductFromAiDraft $create, ApplyAiDraftToProduct $apply): void
    {
        $this->authorizeWizard();
        $draft = $this->draft();

        if ($this->step !== 'confirm') {
            $this->refusal('state');

            return;
        }

        $data = $this->proposalData();

        try {
            $product = $this->runFormAction(fn (): Product => $this->mode === AiProductDraft::IMPROVE
                ? $apply->handle(auth()->user(), $draft, $data)
                : $create->handle(auth()->user(), $draft, $data), '');
        } catch (AuthorizationException) {
            abort(403);
        }

        $body = $this->mode === AiProductDraft::IMPROVE ? __('admin.ai_wizard.applied', ['name' => $product->name]) : __('admin.ai_wizard.created', ['name' => $product->name]);
        session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => $body, 'type' => 'success']);
        $this->redirectRoute('admin.products.edit', $product, navigate: true);
    }

    /**
     * @return list<ProductType>
     */
    public function typeOptions(): array
    {
        return $this->types ??= array_values(array_filter(
            ProductType::cases(),
            fn (ProductType $type): bool => auth()->user()->can('useType', [Product::class, $type]),
        ));
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        $options = [];

        foreach (CategoryTree::flattened() as $node) {
            $options[$node['id']] = str_repeat('— ', $node['depth'] - 1).CategoryTree::name($node['id']);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function brandOptions(): array
    {
        return Brand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        $prepare = app(PrepareAiDraftImages::class);
        $product = $this->authorizeWizard();
        $draft = $this->uuid !== null ? $this->draft() : null;
        $status = $draft?->status;
        $improve = $this->mode === AiProductDraft::IMPROVE;
        $title = $improve ? __('admin.ai_wizard.title_improve', ['name' => $product?->name]) : __('admin.ai_wizard.title_create');

        return view('livewire.admin.products.ai-wizard', [
            'title' => $title,
            'product' => $product,
            'improve' => $improve,
            'draft' => $draft,
            'status' => $status,
            'pending' => $status?->isPending() ?? false,
            'failed' => in_array($status, [AiDraftStatus::Failed, AiDraftStatus::Refused, AiDraftStatus::Capped], true) && ! $this->interrupted($draft),
            'interrupted' => $this->interrupted($draft),
            'interruptedProductId' => $this->interrupted($draft) ? $draft->product_id : null,
            'errorKey' => $draft?->error,
            'types' => $this->typeOptions(),
            'saleModes' => SaleMode::cases(),
            'photoHint' => $this->photoHintAcknowledged(),
            'tiles' => $this->step === 'photos' && $draft !== null ? $this->tiles($draft, $product, $prepare) : [],
            'factViews' => $this->step === 'facts' && $draft !== null ? $this->factViews($draft) : [],
            'messages' => in_array($this->step, ['review', 'confirm'], true) ? (array) ($draft?->result['messages'] ?? []) : [],
            'current' => $improve && $product !== null && in_array($this->step, ['review', 'confirm'], true) ? $this->currentValues($product) : null,
            'specMeta' => in_array($this->step, ['review', 'confirm'], true) ? $this->specMeta($product) : [],
            'slugPreview' => $improve ? $product?->slug : Slug::make($this->textValue('name', 'en') !== '' ? $this->textValue('name', 'en') : $this->textValue('name', 'uz')),
            'changes' => $this->step === 'confirm' ? $this->changeList() : [],
            'imageConfirmations' => $this->step === 'confirm' ? $this->imageConfirmations() : [],
            'noteMax' => (int) config('chatbot.ai.product.notes_max', 2000),
            'maxImages' => (int) config('chatbot.ai.product.max_images', 6),
        ])->title($title);
    }

    /**
     * PW-6, PW-7: ai.generate + the switch with a key + create / update on the
     * fresh product + useType. Memoized for one request (F6); the step 1
     * values are normalized to strings first (F5: a tampered type never 500s).
     */
    private function authorizeWizard(): ?Product
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        $this->normalizeState();
        $key = $this->mode.'|'.$this->productId.'|'.$this->info['type'].'|'.$user->id;

        if ($this->gate !== null && $this->gate['key'] === $key) {
            return $this->gate['product'];
        }

        if ($this->mode === AiProductDraft::IMPROVE) {
            $product = $this->productId !== null ? Product::query()->find($this->productId) : null;

            abort_unless(AiWizardGate::allowsImprove($user, $product), 403);
        } else {
            abort_unless($this->mode === AiProductDraft::CREATE && AiWizardGate::allowsCreate($user, ProductType::tryFrom($this->info['type'])), 403);
            $product = null;
        }

        $this->gate = ['key' => $key, 'product' => $product];

        return $product;
    }

    /**
     * The client-writable state in its expected shape (strings, flags, nested lists).
     */
    private function normalizeState(): void
    {
        $string = fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
        $strings = fn (mixed $list): array => is_array($list) ? array_map($string, array_filter($list, fn (mixed $value): bool => $value === null || is_scalar($value))) : [];

        $this->info = AiDraftProposal::info(is_array($this->info) ? $this->info : []);
        $this->notes = is_string($this->notes) ? $this->notes : '';
        $this->onlyEmpty = is_bool($this->onlyEmpty) ? $this->onlyEmpty : true;

        foreach (['confirmed', 'accept', 'acceptSpecs'] as $property) {
            $this->{$property} = is_array($this->{$property}) ? array_filter($this->{$property}, fn (mixed $value): bool => is_bool($value) || is_scalar($value)) : [];
        }

        $facts = [];

        foreach (is_array($this->facts) ? $this->facts : [] as $field => $value) {
            if ($field === 'views') {
                $facts['views'] = $strings($value);
            } elseif (is_scalar($value) || $value === null) {
                $facts[$field] = $string($value);
            }
        }

        $this->facts = $facts;

        foreach (['texts', 'alts'] as $property) {
            $this->{$property} = array_map($strings, array_filter(is_array($this->{$property}) ? $this->{$property} : [], 'is_array'));
        }

        $specs = [];

        foreach (array_filter(is_array($this->specs) ? $this->specs : [], 'is_array') as $id => $row) {
            foreach ($row as $slot => $value) {
                $row[$slot] = match (true) {
                    $slot === 'text' => $strings($value),
                    $slot === 'options' => array_values(array_filter(is_array($value) ? $value : [], 'is_scalar')),
                    is_scalar($value) || $value === null => $value,
                    default => null,
                };
            }

            $specs[$id] = $row;
        }

        $this->specs = $specs;
    }

    /**
     * @param  list<string>  $path  segments below the root property
     */
    private function shapeAllows(string $shape, array $path, mixed $value): bool
    {
        $depth = count($path);
        $scalar = fn (mixed $item): bool => $item === null || is_string($item) || is_int($item) || is_float($item) || is_bool($item);
        $strings = fn (mixed $item): bool => is_array($item) ? array_filter($item, fn (mixed $leaf): bool => ! $scalar($leaf)) === [] : $scalar($item);

        return match ($shape) {
            'string' => $depth === 0 && is_string($value),
            'bool' => $depth === 0 && is_bool($value),
            'upload' => true,
            'strings' => $depth === 0 ? is_array($value) && $strings($value) : $depth === 1 && $scalar($value),
            'flags' => $depth === 0 ? is_array($value) && $strings($value) : $depth === 1 && $scalar($value),
            'texts' => match ($depth) {
                0 => is_array($value) && array_filter($value, fn (mixed $locales): bool => ! is_array($locales) || ! $strings($locales)) === [],
                1 => is_array($value) && $strings($value),
                2 => $scalar($value),
                default => false,
            },
            'facts' => match (true) {
                $depth === 0 => is_array($value) && array_filter($value, fn (mixed $item, string|int $field): bool => $field === 'views' ? ! is_array($item) || ! $strings($item) : ! $scalar($item), ARRAY_FILTER_USE_BOTH) === [],
                $depth === 1 => $path[0] === 'views' ? is_array($value) && $strings($value) : $scalar($value),
                $depth === 2 => $path[0] === 'views' && $scalar($value),
                default => false,
            },
            'specs' => $depth >= 1 && $depth <= 3 && ($scalar($value) || (is_array($value) && $strings($value))),
            default => false,
        };
    }

    private function draft(): AiProductDraft
    {
        $draft = $this->uuid !== null ? $this->ownedDraft($this->uuid) : null;

        abort_if($draft === null, 404);

        return $draft;
    }

    /**
     * The user's own draft of this mode and product, by uuid (a create draft:
     * any server-set product_id, which it only gets when the apply begins).
     */
    private function ownedDraft(string $uuid): ?AiProductDraft
    {
        $draft = AiProductDraft::query()->ownedBy(auth()->user(), $uuid)->first();

        return $draft !== null && $draft->mode === $this->mode && ($this->mode === AiProductDraft::CREATE || $draft->product_id === $this->productId) ? $draft : null;
    }

    private function resume(AiProductDraft $draft): void
    {
        $this->uuid = $draft->uuid;
        $this->info = AiDraftProposal::info((array) ($draft->input['info'] ?? []));
        $this->notes = (string) ($draft->input['notes'] ?? '');
        $this->syncImages($draft);

        // An interrupted apply (N5): no step can run again, the panel offers "Open product" / "Discard".
        if ($this->interrupted($draft)) {
            $this->step = 'confirm';

            return;
        }

        $this->step = match ($draft->status) {
            AiDraftStatus::Open => 'photos',
            AiDraftStatus::Seeing, AiDraftStatus::FactsReady => 'facts',
            AiDraftStatus::Writing => 'write',
            AiDraftStatus::Ready, AiDraftStatus::Truncated => 'review',
            AiDraftStatus::Applying => 'confirm',
            default => ($draft->result['facts_confirmed'] ?? false) ? 'write' : ($draft->facts !== null ? 'facts' : 'photos'),
        };

        if ($draft->facts !== null && in_array($this->step, ['facts', 'write'], true)) {
            $this->loadFacts($draft);
        }

        if (in_array($this->step, ['review', 'confirm'], true)) {
            $this->loadFacts($draft);
            $this->loadProposals($draft, $this->productId !== null ? Product::query()->find($this->productId) : null);
            // An interrupted apply resumes on confirm ("finish applying", F3); a ready draft is reviewed again.
            $this->step = $draft->status === AiDraftStatus::Applying ? 'confirm' : 'review';
        }
    }

    private function syncImages(AiProductDraft $draft): void
    {
        $this->images = [];

        foreach (array_values((array) ($draft->input['images'] ?? [])) as $index => $image) {
            $this->images[$index] = [
                'source' => (string) $image['source'],
                'clean' => (bool) $image['clean'],
                'action' => (string) $image['action'],
                'replaces' => isset($image['replaces']) ? (int) $image['replaces'] : null,
            ];
        }

        if ($this->mainImage === null || ! isset($this->images[$this->mainImage]) || $this->images[$this->mainImage]['action'] === 'remove') {
            $first = array_key_first(array_filter($this->images, fn (array $image): bool => $image['action'] !== 'remove'));
            $this->mainImage = $first;
        }
    }

    /**
     * @param  array{clean?: bool, action?: string, replaces?: int|null}  $flags
     */
    private function updateImage(UpdateAiDraftImage $update, ?Product $product, int $index, array $flags): void
    {
        $draft = $this->draft();

        try {
            $changed = $update->handle($draft, $product, $index, $flags);
        } catch (ImageRejected $rejected) {
            $this->addError('upload', __($rejected->messageKey()));

            return;
        }

        if (! $changed) {
            $this->refusal('state');
        }

        $this->syncImages($draft->refresh());
    }

    private function imageIndex(mixed $index): ?int
    {
        $index = filter_var($index, FILTER_VALIDATE_INT);

        return $index !== false && isset($this->images[$index]) ? $index : null;
    }

    private function editable(AiProductDraft $draft): bool
    {
        return ! $draft->status->isPending() && ! $draft->status->isFinal() && $draft->status !== AiDraftStatus::Applying && ! AiDraftLock::applyStarted($draft);
    }

    /**
     * An apply that the prune ended (error "apply_interrupted", N5): the
     * wizard shows only "Open product" and "Discard".
     */
    private function interrupted(?AiProductDraft $draft): bool
    {
        return $draft !== null && $draft->status === AiDraftStatus::Failed && $draft->error === 'apply_interrupted';
    }

    private function resetUpload(): void
    {
        if ($this->upload instanceof TemporaryUploadedFile) {
            $this->upload->delete();
        }

        $this->upload = null;
    }

    private function photoHintKey(): string
    {
        return 'ai-wizard:photo-hint:'.self::PHOTO_HINT_VERSION.':'.auth()->id();
    }

    private function photoHintAcknowledged(): bool
    {
        return Cache::get($this->photoHintKey()) === true;
    }

    private function refusal(string $key): void
    {
        $known = ['disabled', 'limited', 'pending', 'busy', 'capped', 'state', 'image', 'no_images', 'error'];

        $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.ai_wizard.refused.'.(in_array($key, $known, true) ? $key : 'error')), type: 'error');
    }

    private function factsError(string $key): string
    {
        [$field, $problem] = array_pad(explode('.', $key, 2), 2, 'invalid');

        if (! in_array($field, [...AiFactsParser::FIELDS, 'views'], true)) {
            return __('admin.ai_wizard.facts_errors.invalid');
        }

        return __('admin.ai_wizard.facts_errors.'.(in_array($problem, ['invalid', 'too_many', 'too_long', 'unsafe'], true) ? $problem : 'invalid'), [
            'field' => __('admin.ai_wizard.facts.'.$field),
        ]);
    }

    /**
     * Facts → the editing state (improve: empty fields filled from the product).
     */
    private function loadFacts(AiProductDraft $draft): void
    {
        $facts = (array) ($draft->facts ?? []);

        $this->facts = [
            'device_type' => (string) ($facts['device_type'] ?? ''),
            'brand' => (string) ($facts['brand'] ?? ''),
            'views' => [],
        ];

        foreach (self::LIST_FACTS as $field) {
            $this->facts[$field] = implode("\n", array_filter((array) ($facts[$field] ?? []), 'is_string'));
        }

        foreach ((array) ($facts['views'] ?? []) as $view) {
            $this->facts['views'][(int) ($view['index'] ?? 0)] = (string) ($view['note'] ?? '');
        }

        $map = (array) ($draft->input['vision_map'] ?? []);
        $suggested = $map[(int) ($facts['main_image'] ?? 1) - 1] ?? null;

        if ($suggested !== null && isset($this->images[(int) $suggested]) && $this->images[(int) $suggested]['action'] !== 'remove') {
            $this->mainImage = (int) $suggested;
        }
    }

    /**
     * The edited facts as AiFactsParser input (warnings and unsure flags stay the model's).
     *
     * @return array<string, mixed>
     */
    private function factsInput(AiProductDraft $draft): array
    {
        $stored = (array) ($draft->facts ?? AiFactsParser::blank());
        $input = [
            'device_type' => is_string($this->facts['device_type'] ?? null) ? $this->facts['device_type'] : '',
            'brand' => is_string($this->facts['brand'] ?? null) ? $this->facts['brand'] : '',
            'views' => [],
            'main_image' => (int) ($stored['main_image'] ?? 1),
            'unsure' => (array) ($stored['unsure'] ?? []),
        ];

        foreach (self::LIST_FACTS as $field) {
            $raw = is_string($this->facts[$field] ?? null) ? $this->facts[$field] : '';
            $input[$field] = array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), fn (string $line): bool => $line !== ''));
        }

        foreach ((array) ($stored['views'] ?? []) as $view) {
            $index = (int) ($view['index'] ?? 0);
            $note = $this->facts['views'][$index] ?? '';
            $input['views'][] = ['index' => $index, 'note' => is_string($note) ? $note : '', 'warnings' => (array) ($view['warnings'] ?? [])];
        }

        $position = array_search($this->mainImage, (array) ($draft->input['vision_map'] ?? []), true);

        if ($position !== false) {
            $input['main_image'] = (int) $position + 1;
        }

        return $input;
    }

    /**
     * Proposals of stage B → the review state.
     */
    private function loadProposals(AiProductDraft $draft, ?Product $product): void
    {
        $result = (array) ($draft->result ?? []);
        $this->texts = [];

        foreach (ProductAiDraft::TEXT_FIELDS as $field) {
            foreach (config('app.supported_locales') as $locale) {
                $value = $result['texts'][$field][$locale] ?? '';
                $this->texts[$field][$locale] = is_string($value) ? $value : '';
            }
        }

        $this->specs = [];

        foreach ((array) ($result['specs'] ?? []) as $row) {
            if (is_array($row) && isset($row['attribute_id'])) {
                $this->specs[(int) $row['attribute_id']] = $row;
            }
        }

        $this->alts = [];

        foreach ((array) ($result['alts'] ?? []) as $index => $locales) {
            foreach (config('app.supported_locales') as $locale) {
                $this->alts[(int) $index][$locale] = is_string($locales[$locale] ?? null) ? $locales[$locale] : '';
            }
        }

        [$this->accept, $this->acceptSpecs] = $this->defaultAccept($product);
    }

    /**
     * Default accept toggles: create — every text, sure specs, alts, images;
     * improve — with "only empty fields" only what is empty now. Unsure specs never (PW-16).
     *
     * @return array{0: array<string, bool>, 1: array<int, bool>}
     */
    private function defaultAccept(?Product $product): array
    {
        $improve = $this->mode === AiProductDraft::IMPROVE && $product !== null;
        $onlyEmpty = $improve && $this->onlyEmpty;
        $accept = [];

        foreach (ProductAiDraft::TEXT_FIELDS as $field) {
            $proposed = array_filter((array) ($this->texts[$field] ?? []), fn (mixed $text): bool => is_string($text) && trim($text) !== '') !== [];
            $currentEmpty = ! $improve || array_filter($product->getTranslations($field), fn ($text): bool => trim(strip_tags((string) $text)) !== '') === [];
            $accept[$field] = $proposed && (! $onlyEmpty || $currentEmpty);
        }

        $filled = $improve ? ProductAttributeValue::query()->where('product_id', $product->id)->distinct()->pluck('attribute_id')->map(fn ($id): int => (int) $id)->all() : [];

        $specs = [];

        foreach ($this->specs as $attributeId => $row) {
            $specs[(int) $attributeId] = ! ($row['unsure'] ?? true) && (! $onlyEmpty || ! in_array((int) $attributeId, $filled, true));
        }

        $altsProposed = array_filter($this->alts, fn (mixed $locales): bool => is_array($locales) && array_filter($locales) !== []) !== [];
        $altsEmpty = ! $improve || $product->getMedia(Product::IMAGES)->every(fn (Media $media): bool => array_filter((array) $media->getCustomProperty('alt', [])) === []);
        $accept['alts'] = $altsProposed && (! $onlyEmpty || $altsEmpty);
        $accept['images'] = ! $improve || $this->imageChanges() !== [];

        return [$accept, $specs];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function altsInput(): array
    {
        $alts = [];

        foreach ($this->alts as $index => $locales) {
            if (is_array($locales) && filter_var($index, FILTER_VALIDATE_INT) !== false) {
                $alts[(int) $index] = array_filter($locales, 'is_string');
            }
        }

        return $alts;
    }

    private function proposalData(): ProductAiDraft
    {
        $accepted = [];

        foreach ($this->accept as $key => $on) {
            if ($on === true && is_string($key) && (in_array($key, ProductAiDraft::TEXT_FIELDS, true) || in_array($key, ['alts', 'images'], true))) {
                $accepted[] = $key;
            }
        }

        foreach ($this->acceptSpecs as $id => $on) {
            if ($on === true && filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $accepted[] = 'spec:'.(int) $id;
            }
        }

        if ($this->mode === AiProductDraft::CREATE) {
            $accepted = [...$accepted, ...ProductAiDraft::TEXT_FIELDS, 'alts', 'images'];
        }

        $images = [];

        foreach ($this->images as $index => $image) {
            $images[$index] = [
                'clean' => $image['clean'],
                'action' => $image['action'],
                'replaces' => $image['replaces'],
                'confirmed' => ($this->confirmed[$index] ?? false) === true,
            ];
        }

        return ProductAiDraft::fromArray([
            'mode' => $this->mode,
            'product_id' => $this->productId,
            'info' => $this->info,
            'texts' => $this->texts,
            'specs' => array_values(array_filter($this->specs, 'is_array')),
            'images' => $images,
            'alts' => $this->altsInput(),
            'main' => $this->mainImage,
            'accepted' => array_values(array_unique($accepted)),
        ]);
    }

    /**
     * Image changes the apply would make (improve).
     *
     * @return list<array{index: int, kind: string}>
     */
    private function imageChanges(): array
    {
        $changes = [];

        foreach ($this->images as $index => $image) {
            $kind = match (true) {
                $image['source'] === 'upload' && $image['action'] === 'replace' => 'replace',
                $image['source'] === 'upload' && $image['action'] === 'add' => 'add',
                $image['source'] === 'media' && $image['action'] === 'remove' => 'remove',
                $image['source'] === 'media' && $image['clean'] => 'clean',
                default => null,
            };

            if ($kind !== null) {
                $changes[] = ['index' => $index, 'kind' => $kind];
            }
        }

        return $changes;
    }

    /**
     * Replace / remove / in-place clean of existing images that need an explicit confirmation (PW-22, L11).
     *
     * @return list<array{index: int, kind: string, target: int}>
     */
    private function imageConfirmations(): array
    {
        $list = [];

        foreach ($this->images as $index => $image) {
            if ($image['source'] === 'upload' && $image['action'] === 'replace' && $image['replaces'] !== null) {
                $list[] = ['index' => $index, 'kind' => 'replace', 'target' => $image['replaces']];
            } elseif ($image['source'] === 'media' && $image['action'] === 'remove') {
                $list[] = ['index' => $index, 'kind' => 'remove', 'target' => $index];
            } elseif ($image['source'] === 'media' && $image['action'] === 'keep' && $image['clean']) {
                $list[] = ['index' => $index, 'kind' => 'clean', 'target' => $index];
            }
        }

        return $list;
    }

    /**
     * What the apply will write (keys and counts for the confirm step).
     *
     * @return array{texts: list<string>, specs: int, alts: bool, images: list<array{index: int, kind: string}>, info: list<string>}
     */
    private function changeList(): array
    {
        $create = $this->mode === AiProductDraft::CREATE;

        return [
            'texts' => array_values(array_filter(ProductAiDraft::TEXT_FIELDS, fn (string $field): bool => ($create || ($this->accept[$field] ?? false)) && array_filter((array) ($this->texts[$field] ?? [])) !== [])),
            'specs' => count(array_filter(array_keys($this->specs), fn ($id): bool => $this->acceptSpecs[$id] ?? false)),
            'alts' => $create || ($this->accept['alts'] ?? false),
            'images' => $create || ($this->accept['images'] ?? false) ? $this->imageChanges() : [],
            'info' => $this->uuid !== null && ! $create ? ApplyAiDraftToProduct::changedInfo($this->draft()) : [],
        ];
    }

    /**
     * Photo tiles with before / after previews (data-URIs, PW-3; the 400 px
     * JPEGs are kept in the draft's preview/ folder, never in the cache, N1).
     *
     * @return list<array<string, mixed>>
     */
    private function tiles(AiProductDraft $draft, ?Product $product, PrepareAiDraftImages $prepare): array
    {
        $media = $product !== null ? $product->getMedia(Product::IMAGES)->keyBy('id') : collect();
        $tiles = [];

        foreach (array_values((array) ($draft->input['images'] ?? [])) as $index => $image) {
            $tile = ['index' => $index, 'flags' => $this->images[$index] ?? null, 'before' => null, 'after' => null];

            if ($image['source'] === 'upload') {
                $plain = $prepare->uploadFile($draft, (int) $image['ref'], false);
                $clean = $prepare->uploadFile($draft, (int) $image['ref'], true);
                $tile['before'] = $prepare->preview($draft, $plain);
                $tile['after'] = $prepare->preview($draft, $clean);
            } else {
                $medium = $media->get((int) $image['ref']);
                $tile['before'] = $medium !== null ? AdminMediaUrl::preview($medium, 'thumb') : null;
                $tile['after'] = $prepare->preview($draft, $prepare->existingCleanFile($draft, (int) $image['ref']));
            }

            $tiles[] = $tile;
        }

        return $tiles;
    }

    private function textValue(string $field, string $locale): string
    {
        $value = $this->texts[$field][$locale] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Facts step: per vision image its draft index and warnings.
     *
     * @return list<array{number: int, index: int|null, warnings: list<string>}>
     */
    private function factViews(AiProductDraft $draft): array
    {
        $map = (array) ($draft->input['vision_map'] ?? []);
        $views = [];

        foreach ((array) ($draft->facts['views'] ?? []) as $view) {
            $number = (int) ($view['index'] ?? 0);
            $index = $map[$number - 1] ?? null;
            $views[] = [
                'number' => $number,
                'index' => $index !== null ? (int) $index : null,
                'warnings' => array_values(array_intersect((array) ($view['warnings'] ?? []), AiFactsParser::WARNINGS)),
            ];
        }

        return $views;
    }

    /**
     * Improve: the product's current texts and spec values for the diff.
     *
     * @return array{texts: array<string, array<string, string>>, specs: array<int, string>}
     */
    private function currentValues(Product $product): array
    {
        $texts = [];

        foreach (ProductAiDraft::TEXT_FIELDS as $field) {
            foreach (config('app.supported_locales') as $locale) {
                $texts[$field][$locale] = trim(strip_tags((string) $product->getTranslation($field, $locale, false)));
            }
        }

        $specs = [];

        foreach ($product->attributeValues()->with(['attribute', 'option'])->get() as $value) {
            $specs[$value->attribute_id] = trim(($specs[$value->attribute_id] ?? '').', '.$value->displayValue(), ', ');
        }

        return ['texts' => $texts, 'specs' => $specs];
    }

    /**
     * Names, units, types and proposed value labels of the spec proposals.
     *
     * @return array<int, array{name: string, unit: string, type: AttributeType, value: string}>
     */
    private function specMeta(?Product $product): array
    {
        if ($this->specs === []) {
            return [];
        }

        $attributes = Attribute::query()->with('options')->whereKey(array_map('intval', array_keys($this->specs)))->get()->keyBy('id');
        $meta = [];

        foreach ($this->specs as $id => $row) {
            $attribute = $attributes->get((int) $id);

            if ($attribute === null) {
                continue;
            }

            $value = match ($attribute->type) {
                AttributeType::Select => (string) $attribute->options->firstWhere('id', (int) ($row['option'] ?? 0))?->value,
                AttributeType::Multiselect => $attribute->options->whereIn('id', array_map('intval', (array) ($row['options'] ?? [])))->map(fn ($option): string => (string) $option->value)->implode(', '),
                AttributeType::Boolean => ($row['bool'] ?? '') === '1' ? __('admin.common.yes') : (($row['bool'] ?? '') === '0' ? __('admin.common.no') : ''),
                default => '',
            };

            $meta[(int) $id] = ['name' => (string) $attribute->name, 'unit' => (string) $attribute->unit, 'type' => $attribute->type, 'value' => $value];
        }

        return $meta;
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private function wizardRoute(): array
    {
        return $this->productId !== null
            ? ['admin.products.ai.improve', ['product' => $this->productId]]
            : ['admin.products.ai.create', []];
    }
}
